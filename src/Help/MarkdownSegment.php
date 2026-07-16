<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * One styled run of inline markdown text. Links carry their followable
 * target and a group id so a link split across wrapped lines still reads as
 * one link (Tab focus moves per group).
 */
final class MarkdownSegment
{
    public function __construct(
        public readonly string $text,
        public readonly MarkdownSpanKind $kind = MarkdownSpanKind::Plain,
        public readonly ?string $target = null,
        public readonly int $group = -1,
    ) {}
}
