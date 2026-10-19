<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Located native-parser failure, with zero-based source positions. */
final class MermaidSyntaxError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $column,
    ) {
        parent::__construct('Line '.($sourceLine + 1).', column '.($column + 1).': '.$message);
    }
}
