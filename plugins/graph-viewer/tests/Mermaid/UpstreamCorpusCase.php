<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every pinned upstream end-to-end diagram, verbatim, renders natively: the picture exports
 * and the terminal rows draw, configuration and all. Family tests check what they show.
 *
 * The corpus is dealt round-robin across {@see self::PARTS} test files. As one file it
 * took ~208 s, and a file is one coverage:plugins shard — the pass's whole floor.
 */
abstract class UpstreamCorpusCase extends TestCase
{
    /** How many `UpstreamCorpusPart<N>Test` files share the corpus. */
    public const int PARTS = 6;

    private const string ROOT = __DIR__.'/../fixtures/mermaid/upstream/';

    /** @return array{commit: string, files: array<string, string>} */
    private static function provenance(): array
    {
        /** @var array{commit: string, files: array<string, string>} */
        return \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/upstream-provenance.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Every PARTS-th diagram, starting at $part: round-robin, so each family is spread
     * over every part and no part gets all of the slow ones.
     *
     * @return iterable<string, array{string, string}>
     */
    protected static function part(int $part): iterable
    {
        $index = 0;
        foreach (self::provenance()['files'] as $path => $hash) {
            if ($part !== ($index++ % self::PARTS)) {
                continue;
            }
            yield $path => [$path, $hash];
        }
    }

    /** @return iterable<string, array{string, string}> */
    abstract public static function diagrams(): iterable;

    #[DataProvider('diagrams')]
    public function testEachDiagramRendersNatively(string $path, string $hash): void
    {
        $source = (string) \file_get_contents(self::ROOT.$path);
        $this->assertSame($hash, \hash('sha256', $source), 'the file is the pinned one');
        $this->assertStringStartsWith('<svg', NativeFixtureExport::render($source, Format::Svg));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
    }
}
