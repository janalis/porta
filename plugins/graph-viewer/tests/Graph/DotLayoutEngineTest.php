<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotLayoutEngine;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\Image\ImageDecoder;
use Porta\Tests\Support\GraphvizBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;

final class DotLayoutEngineTest extends TestCase
{
    use GraphvizBinary;
    use PreservesPath;
    use PumpsLoop;

    private const string SMALL_DOT = 'digraph G { rankdir=LR; A -> B [label="calls"]; B -> C; }';

    private static function stub(string $name): string
    {
        return \dirname(__DIR__, levels: 4).'/tests/Support/'.$name;
    }

    private function engine(string $stub): DotLayoutEngine
    {
        return new DotLayoutEngine(sys_get_temp_dir(), self::stub($stub));
    }

    public function testLaysOutThroughTheStubBinary(): void
    {
        $engine = $this->engine('fake-dot');
        $layout = null;
        $error = null;
        $engine->layout(
            self::SMALL_DOT,
            static function (GraphLayout $done) use (&$layout): void {
                $layout = $done;
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$layout, &$error): bool {
            return null !== $layout || null !== $error;
        }, what: 'layout to complete');

        $this->assertNull($error);
        $this->assertNotNull($layout);
        $this->assertSame(3, $layout->nodeCount());
    }

    public function testFailureSurfacesDotsStderr(): void
    {
        $engine = $this->engine('fake-dot-fail');
        $error = null;
        $engine->layout(
            'digraph {',
            static function (): void {
                self::fail('onDone must not fire for a failing dot');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the error callback');

        $this->assertStringContainsString('syntax error in line 3', (string) $error);
    }

    public function testGarbageOutputSurfacesAsAParseError(): void
    {
        $engine = $this->engine('fake-dot-garbage');
        $error = null;
        $engine->layout(
            self::SMALL_DOT,
            static function (): void {
                self::fail('onDone must not fire for garbage output');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the parse-error callback');

        $this->assertStringContainsString('invalid JSON', (string) $error);
    }

    public function testANewLayoutSupersedesTheInFlightOne(): void
    {
        $engine = $this->engine('fake-dot');
        $fired = [];
        $onError = static function (string $message): void {
            self::fail("unexpected layout error: {$message}");
        };
        $engine->layout(
            self::SMALL_DOT,
            static function () use (&$fired): void {
                $fired[] = 'first';
            },
            $onError,
        );
        $engine->layout(
            self::SMALL_DOT,
            static function () use (&$fired): void {
                $fired[] = 'second';
            },
            $onError,
        );
        $this->await(static function () use (&$fired): bool {
            return [] !== $fired;
        }, what: 'the surviving layout');
        $this->pump(0.2);

        $this->assertSame(['second'], $fired, 'the superseded call went silent, latest wins');
    }

    public function testMissingBinaryErrorsWithoutSpawning(): void
    {
        putenv('PATH=/nonexistent');
        $engine = new DotLayoutEngine(sys_get_temp_dir(), null);
        $error = null;
        $engine->layout(
            self::SMALL_DOT,
            static function (): void {
                self::fail('onDone must not fire without a binary');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );

        $this->assertSame('graphviz not found', $error, 'synchronous — no process was involved');
        $this->assertNull($engine->binary());

        $exportError = 'unset';
        $engine->export(self::SMALL_DOT, GraphExportFormat::Svg, '/tmp/never-written.svg', static function (?string $message) use (&$exportError): void {
            $exportError = $message;
        });
        $this->assertSame('graphviz not found', $exportError);
    }

    public function testExplicitDotPathWinsOverDiscovery(): void
    {
        $this->assertSame(self::stub('fake-dot'), $this->engine('fake-dot')->binary());
    }

    public function testExportWritesTheTargetFile(): void
    {
        $out = sys_get_temp_dir().'/porta-graph-export-'.getmypid().'.svg';
        if (is_file($out)) {
            unlink($out);
        }
        $engine = $this->engine('fake-dot');
        $result = 'unset';
        $engine->export(self::SMALL_DOT, GraphExportFormat::Svg, $out, static function (?string $message) use (&$result): void {
            $result = $message;
        });
        $this->await(static function () use (&$result): bool {
            return 'unset' !== $result;
        }, what: 'the export callback');

        $this->assertNull($result);
        $this->assertFileExists($out);
        $this->assertStringContainsString('-Tsvg', (string) file_get_contents($out), 'the stub recorded the format flag');
        unlink($out);
    }

    public function testExportFailureReportsTheError(): void
    {
        $engine = $this->engine('fake-dot-fail');
        $result = null;
        $engine->export(self::SMALL_DOT, GraphExportFormat::Png, '/tmp/never-written.png', static function (?string $message) use (&$result): void {
            $result = $message;
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the export-error callback');

        $this->assertStringContainsString('syntax error', (string) $result);
    }

    public function testCancelIsIdempotentAndStopsInFlightWork(): void
    {
        $engine = $this->engine('fake-dot');
        $fired = false;
        $engine->layout(
            self::SMALL_DOT,
            static function () use (&$fired): void {
                $fired = true;
            },
            static function () use (&$fired): void {
                $fired = true;
            },
        );
        $engine->cancel();
        $engine->cancel();
        $this->pump(0.2);

        $this->assertFalse($fired, 'a cancelled layout never reports');
    }

    public function testRendersPngBytesAtTheRequestedDpi(): void
    {
        $engine = $this->engine('fake-dot');
        // 72 is the whole coordinate identity: one PostScript point per pixel,
        // which is what lets a dot bitmap and its layout share hit-testing.
        $this->assertSame(72, $engine->baseDpi());
        $png = null;
        $onError = static function (string $message): void {
            self::fail("unexpected render error: {$message}");
        };

        $engine->render(
            self::SMALL_DOT,
            144,
            static function (string $bytes) use (&$png): void {
                $png = $bytes;
            },
            $onError,
        );
        $this->await(static function () use (&$png): bool {
            return null !== $png;
        }, what: 'the PNG bytes');

        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image, 'the bytes decode as an image — no temp file in between');
        // The stub sizes its PNG from -Gdpi: 10 px per 72 dpi.
        $this->assertSame(20, $image->width, 'the dpi reached dot as an argument');
    }

    public function testRenderFailureSurfacesDotsStderr(): void
    {
        // A dot built without libgd fails exactly here, which is what makes the
        // viewer fall back to its cell rendering.
        $engine = $this->engine('fake-dot-fail');
        $error = null;
        $engine->render(
            self::SMALL_DOT,
            72,
            static function (): void {
                self::fail('onPng must not fire for a failing dot');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the render-error callback');

        $this->assertStringContainsString('syntax error', (string) $error);
    }

    public function testRenderAndLayoutDoNotCancelEachOther(): void
    {
        // Separate slots: a zoom-driven re-render must not kill the geometry
        // request the bitmap is about to be drawn with.
        $engine = $this->engine('fake-dot');
        $layout = null;
        $png = null;
        $onError = static function (string $message): void {
            self::fail("unexpected error: {$message}");
        };
        $engine->layout(
            self::SMALL_DOT,
            static function (GraphLayout $done) use (&$layout): void {
                $layout = $done;
            },
            $onError,
        );
        $engine->render(
            self::SMALL_DOT,
            72,
            static function (string $bytes) use (&$png): void {
                $png = $bytes;
            },
            $onError,
        );

        $this->await(static function () use (&$layout, &$png): bool {
            return null !== $layout && null !== $png;
        }, what: 'both answers');
        $this->assertSame(3, $layout->nodeCount());
        $this->assertNotSame('', $png);
    }

    public function testANewRenderSupersedesTheInFlightOne(): void
    {
        $engine = $this->engine('fake-dot');
        $fired = [];
        $onError = static function (string $message): void {
            self::fail("unexpected render error: {$message}");
        };
        $engine->render(
            self::SMALL_DOT,
            72,
            static function () use (&$fired): void {
                $fired[] = 'first';
            },
            $onError,
        );
        $engine->render(
            self::SMALL_DOT,
            144,
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

    public function testRenderWithoutABinaryErrorsWithoutSpawning(): void
    {
        putenv('PATH=/nonexistent');
        $error = null;
        new DotLayoutEngine(sys_get_temp_dir(), null)->render(
            self::SMALL_DOT,
            72,
            static function (): void {
                self::fail('onPng must not fire without a binary');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );

        $this->assertSame('graphviz not found', $error, 'synchronous — no process was involved');
    }

    public function testRealGraphvizSmokeTest(): void
    {
        $this->skipWithoutDot();
        $engine = new DotLayoutEngine(sys_get_temp_dir(), null);
        $this->assertNotNull($engine->binary());

        $layout = null;
        $error = null;
        $engine->layout(
            self::SMALL_DOT,
            static function (GraphLayout $done) use (&$layout): void {
                $layout = $done;
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$layout, &$error): bool {
            return null !== $layout || null !== $error;
        }, what: 'real dot layout');

        $this->assertNull($error);
        $this->assertNotNull($layout);
        $this->assertSame(3, $layout->nodeCount());
        $this->assertSame(2, $layout->edgeCount());
    }

    public function testRealGraphvizRendersABitmapWhenItHasPngSupport(): void
    {
        $this->skipWithoutDot();
        $png = null;
        $error = null;
        new DotLayoutEngine(sys_get_temp_dir(), null)->render(
            self::SMALL_DOT,
            72,
            static function (string $bytes) use (&$png): void {
                $png = $bytes;
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$png, &$error): bool {
            return null !== $png || null !== $error;
        }, what: 'real dot render');

        if (null !== $error) {
            // PNG output is optional in graphviz (it needs libgd), and a build
            // without it is the case the viewer's cell fallback exists for.
            self::markTestSkipped('this dot has no PNG support: '.$error);
        }
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image, 'real dot emitted a decodable PNG');
        $this->assertGreaterThan(0, $image->width);
    }
}
