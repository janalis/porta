<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Range of a YAML alias key plus its surrounding mapping context. */
final readonly class SequenceYamlKey
{
    public function __construct(
        public int $start,
        public int $end,
        public ?int $pairEnd = null,
        public bool $objectKey = false,
    ) {}
}
