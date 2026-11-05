<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

/** Part 2 of the upstream corpus; {@see UpstreamCorpusCase} deals it. */
final class UpstreamCorpusPart2Test extends UpstreamCorpusCase
{
    #[\Override]
    public static function diagrams(): iterable
    {
        return self::part(1);
    }
}
