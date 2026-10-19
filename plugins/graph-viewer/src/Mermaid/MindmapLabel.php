<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Mindmap node syntax is also the node vocabulary of Mermaid's Kanban grammar. */
final class MindmapLabel
{
    /** @return array{string, string, ?MermaidMarkdown} the label, its shape, and its Markdown when quoted so */
    public static function parse(MermaidStatement $statement): array
    {
        $text = $statement->text;
        $match = [];
        if (1 !== \preg_match('/^[^\[(){}\n]*?(\(\(|\{\{|\)\)|-\)|\(-|[\[()])/', $text, $match)) {
            $text = \trim(\explode('%%', $text, limit: 2)[0]);
            if ('' === $text || 1 === \preg_match('/[\[\](){}\n]/', $text)) {
                throw $statement->error('Invalid mindmap node');
            }

            return [MermaidText::decode($text), 'mindmap-plain', null];
        }
        $open = $match[1];
        $body = \substr($text, \strlen($match[0]));
        $close = match ($open) {
            '[' => ']',
            '{{' => '}}',
            '((' => '))',
            '))' => '((',
            ')' => '(',
            '-)' => '(-',
            '(-' => '-)',
            default => ')',
        };
        $pattern = '/^(.*?)'.\preg_quote($close, delimiter: '/').'\s*(?:%%[^\n]*)?$/s';
        if (1 !== \preg_match($pattern, $body, $match) || '' === \trim($match[1])) {
            throw $statement->error('Unclosed or empty mindmap shape');
        }
        $label = FlowchartPath::label($match[1]);
        $shape = match ($open) {
            '(' => 'rounded',
            '((' => 'circle',
            '{{' => 'hexagon',
            ')' => 'cloud',
            '))' => 'bang',
            default => 'rect',
        };

        return [$label, $shape, FlowchartPath::markdown($match[1])];
    }
}
