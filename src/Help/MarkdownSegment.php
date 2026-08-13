<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * One styled run of inline markdown text. Links carry their followable
 * target and a group id so a link split across wrapped lines still reads as
 * one link (Tab focus moves per group).
 */
final readonly class MarkdownSegment
{
    public function __construct(
        public string $text,
        public MarkdownSpanKind $kind = MarkdownSpanKind::Plain,
        public ?string $target = null,
        public int $group = -1,
    ) {}
}
