<?php

declare(strict_types=1);

namespace Porta\Markdown;

use Porta\Markdown\Block\BlockParser;
use Porta\Markdown\Block\TreeBuilder;
use Porta\Markdown\Node\Document;

/**
 * Markdown text into a document tree: CommonMark 0.31.2 with GitHub's
 * extensions — tables, task lists, strikethrough, extended autolinks,
 * footnotes, alerts and emoji shortcodes. Every node carries the source
 * range it was written in.
 */
final class MarkdownParser
{
    public function parse(string $markdown): Document
    {
        return new TreeBuilder()->build(new BlockParser()->parse($markdown));
    }
}
