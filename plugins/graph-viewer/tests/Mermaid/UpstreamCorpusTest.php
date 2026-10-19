<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every pinned upstream end-to-end diagram, verbatim, renders natively: the picture exports
 * and the terminal rows draw, configuration and all. Family tests check what they show.
 */
final class UpstreamCorpusTest extends TestCase
{
    private const string ROOT = __DIR__.'/../fixtures/mermaid/upstream/';

    /** @return array{commit: string, files: array<string, string>} */
    private static function provenance(): array
    {
        /** @var array{commit: string, files: array<string, string>} */
        return \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/upstream-provenance.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, string}> */
    public static function diagrams(): iterable
    {
        foreach (self::provenance()['files'] as $path => $hash) {
            yield $path => [$path, $hash];
        }
    }

    #[DataProvider('diagrams')]
    public function testEachDiagramRendersNatively(string $path, string $hash): void
    {
        $source = (string) \file_get_contents(self::ROOT.$path);
        $this->assertSame($hash, \hash('sha256', $source), 'the file is the pinned one');
        $this->assertStringStartsWith('<svg', NativeFixtureExport::render($source, Format::Svg));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
    }
}
