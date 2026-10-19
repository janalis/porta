<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Authored endpoints remain ordered even when a marker points backwards. */
final readonly class UsecaseRelation
{
    public function __construct(
        public string $from,
        public string $to,
        public string $arrow,
        public string $kind,
        public string $label,
        public ?string $id,
        public MermaidStatement $statement,
        /** A backtick label's Markdown body, which pinned labels style. */
        public ?string $markdown = null,
    ) {}
}
