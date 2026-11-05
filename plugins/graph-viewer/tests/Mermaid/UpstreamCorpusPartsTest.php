<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;

/** The corpus parts are exactly PARTS files, and between them they deal every diagram once. */
final class UpstreamCorpusPartsTest extends TestCase
{
    public function testThePartsDealTheWholeCorpusOnce(): void
    {
        $dealt = [];
        for ($part = 1; $part <= UpstreamCorpusCase::PARTS; ++$part) {
            $class = __NAMESPACE__.'\\UpstreamCorpusPart'.$part.'Test';
            $this->assertTrue(\class_exists($class), $class.' exists, or its share of the corpus never runs');
            /** @var class-string<UpstreamCorpusCase> $class */
            foreach ($class::diagrams() as $path => $_) {
                $dealt[] = $path;
            }
        }
        $this->assertFalse(\class_exists(__NAMESPACE__.'\\UpstreamCorpusPart'.(UpstreamCorpusCase::PARTS + 1).'Test'), 'a part beyond PARTS would deal nothing');

        $all = \array_keys(\json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/upstream-provenance.json'), associative: true, flags: \JSON_THROW_ON_ERROR)['files']);
        \sort($all);
        \sort($dealt);
        $this->assertSame($all, $dealt, 'every diagram once, none twice');
    }
}
