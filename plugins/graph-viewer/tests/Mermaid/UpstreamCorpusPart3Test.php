<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

/** Part 3 of the upstream corpus; {@see UpstreamCorpusCase} deals it. */
final class UpstreamCorpusPart3Test extends UpstreamCorpusCase
{
    #[\Override]
    public static function diagrams(): iterable
    {
        return self::part(2);
    }
}
