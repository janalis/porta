<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One pinned upstream diagram of each family, verbatim, renders natively here:
 * the picture exports and the terminal rows draw, configuration and all.
 *
 * A sample, not the corpus. All 1237 diagrams ran on every check — 140 s of a
 * coverage pass, eight shards that covered no plugin line the others did not —
 * and what they tested was janalis/viz, which renders the whole corpus in its
 * own suite. What stays is what is Porta's: each family reaches the rows.
 */
final class UpstreamCorpusSampleTest extends TestCase
{
    private const string ROOT = __DIR__.'/../fixtures/mermaid/upstream/';

    /** @return iterable<string, array{string, string}> */
    public static function diagrams(): iterable
    {
        /** @var array{files: array<string, string>} $provenance */
        $provenance = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/upstream-provenance.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        // The first diagram of each family. The files all stay: every line of
        // them is a label DisplayWidthParityTest measures against the terminal.
        $seen = [];
        foreach ($provenance['files'] as $path => $hash) {
            $family = \explode('/', $path, limit: 2)[0];
            if (\array_key_exists($family, $seen)) {
                continue;
            }
            $seen[$family] = true;
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

    public function testTheSampleHoldsOneDiagramOfEveryFamily(): void
    {
        $families = \array_map(static fn (string $path): string => \explode('/', $path, limit: 2)[0], \array_keys(\iterator_to_array(self::diagrams())));

        $this->assertSame(\array_values(\array_unique($families)), $families, 'one of each');
        $this->assertCount(33, $families, 'every family the pinned corpus has');
    }
}
