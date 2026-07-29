<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Graph\DotLayoutEngine;
use Porta\Graph\GraphExportFormat;
use Porta\Graph\GraphLayout;
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
        return __DIR__.'/../Support/'.$name;
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
}
