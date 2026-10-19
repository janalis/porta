<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Adapt the pinned JSON schema's primitives and tags without touching quoted or block-scalar text. */
final class SequenceYamlSchema
{
    private const string URI = 'tag:yaml.org,2002:';
    private const string KEY = '(?:[a-zA-Z_][a-zA-Z0-9_-]*|\x22(?:alias|type)\x22|\x27(?:alias|type)\x27)[\t ]*:';

    public static function normalize(string $yaml, MermaidStatement $statement): string
    {
        [$yaml, $handles] = SequenceYamlDirectives::read($yaml, $statement);
        if (!self::needsNormalization($yaml)) {
            return $yaml;
        }
        self::root($yaml, $statement);
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $head = true;
        $flow = 0;
        $collections = [];
        $last = '';
        $leading = true;
        $anchored = false;
        $ownLine = false;
        $indent = 0;
        $parts = [];
        $copied = 0;
        $until = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            $atStart = $leading;
            self::line($char, $leading, $indent);
            $previous = $last;
            if (!\ctype_space($char)) {
                $last = $char;
            }
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            if ($offset < $until) {
                $slots->advance($yaml, $offset, structural: false, anchor: false, quote: false, tag: false);
                $context->skip($offset);
                continue;
            }
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ($atStart && $structural && 0 === $flow && 0 === $indent && self::documentMarker($yaml, $offset)) {
                $head = true;
                $anchored = false;
                $until = $offset + 3;
                continue;
            }
            if ($text->opensQuote) {
                $head = false;
            }
            if ($head && $text->opensAnchor) {
                if ($anchored) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $anchored = true;
                $ownLine = $atStart;
            }
            if (!$structural) {
                continue;
            }
            if (self::unseparatedKey($yaml, $offset, $flow, $previous)) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $opening = self::collection($char, $head, $slots, $flow, $collections);
            [$sequence, $separator] = self::indicators($yaml, $offset, $atStart || $head, $flow, $previous);
            if ($opening || $separator || $sequence) {
                $head = true;
                $anchored = false;
                $ownLine = false;
                $primitive = self::value($yaml, $offset, $collections, $sequence, $context, $statement);
                if (null !== $primitive) {
                    [$start, $until, $replacement] = $primitive;
                    $parts[] = \substr($yaml, $copied, $start - $copied).$replacement;
                    $copied = $until;
                    $head = false;
                }
                continue;
            }
            if (self::tagHead($yaml, $offset, $head, $atStart, $context)) {
                [$tag, $until] = self::nodeHeader($yaml, $offset, $anchored, $statement, $handles, $indent, $atStart || $ownLine, $context, $slots);
                $parts[] = \substr($yaml, $copied, $offset - $copied).$tag;
                $copied = $until;
                continue;
            }
            if (!\ctype_space($char)) {
                $head = false;
            }
        }
        $parts[] = \substr($yaml, $copied);

        return \implode('', $parts);
    }

    /** @param list<string> $collections */
    private static function collection(string $char, bool $head, SequenceYamlSlots $slots, int &$flow, array &$collections): bool
    {
        $opening = ($head || $slots->keyNode) && \str_contains('{[', $char);
        if ($opening) {
            ++$flow;
            $collections[] = $char;
        }
        if ($flow > 0 && \str_contains('}]', $char)) {
            --$flow;
            \array_pop($collections);
        }

        return $opening;
    }

    private static function tagHead(string $yaml, int $offset, bool $head, bool $atStart, SequenceYamlFlowContext $context): bool
    {
        return '!' === $yaml[$offset] && ($head || ($atStart && (self::key($yaml, $offset) || $context->mappingKey)));
    }

    private static function unseparatedKey(string $yaml, int $offset, int $flow, string $previous): bool
    {
        return ':' === $yaml[$offset] && 0 === $flow && \in_array($previous, ['"', "'"], strict: true) && !\ctype_space($yaml[$offset + 1] ?? "\n");
    }

    // @mago-expect lint:no-boolean-flag-parameter -- This is mutable lexical line state.
    private static function line(string $char, bool &$leading, int &$indent): void
    {
        $leading = "\n" === $char || ($leading && \ctype_space($char));
        if ("\n" === $char) {
            $indent = 0;
        }
        if ($leading && \in_array($char, [' ', "\t"], strict: true)) {
            ++$indent;
        }
    }

    /** @return array{bool, bool} sequence and value separators */
    private static function indicators(string $yaml, int $offset, bool $head, int $flow, string $previous): array
    {
        $char = $yaml[$offset];
        $next = $yaml[$offset + 1] ?? '';

        return [
            $head && \in_array($char, ['-', '?'], strict: true) && \ctype_space($next),
            (',' === $char && $flow > 0) || (':' === $char && (\ctype_space($next) || \in_array($previous, ['"', "'"], strict: true))),
        ];
    }

    /**
     * @param list<string> $collections
     * @return array{int, int, string}|null
     */
    private static function value(string $yaml, int $offset, array $collections, bool $sequence, SequenceYamlFlowContext $context, MermaidStatement $statement): ?array
    {
        $char = $yaml[$offset];
        $scalar = ':' === $char || $sequence || ('[' === \array_last($collections) && \in_array($char, ['[', ','], strict: true));

        return $scalar ? SequenceYamlScalar::normalize($yaml, $offset + 1, $collections, $statement, [] !== $collections ? $context->indent : null, $context->nodeIndent) : null;
    }

    /**
     * @param array<string, string> $handles
     * @return array{string, int}
     */
    private static function nodeHeader(
        string $yaml,
        int $offset,
        bool $anchored,
        MermaidStatement $statement,
        array $handles,
        int $indent,
        bool $ownLine,
        SequenceYamlFlowContext $context,
        SequenceYamlSlots $slots,
    ): array {
        $flowIndent = $slots->depth > 0 ? $context->indent : null;
        $keyNode = null !== $flowIndent && null !== self::flowKey($yaml, $offset, $slots, $flowIndent, $statement);
        if (null === $flowIndent && $context->mappingKey && $slots->keyNode) {
            $line = $offset;
            while ($line > 0 && "\n" !== $yaml[$line - 1]) {
                --$line;
            }
            $keyNode = null !== SequenceYamlBlockKey::property($yaml, $offset, $line, $statement);
        }
        if (null === $flowIndent && !$context->mappingKey) {
            SequenceYamlBlockKey::validateValue($yaml, $offset, $statement);
        }
        $directValue = \in_array($slots->previous, [':', '-'], strict: true);

        return self::header(
            $yaml,
            $offset,
            $anchored,
            $statement,
            $handles,
            $directValue || null !== $slots->question ? $context->nodeIndent : $indent,
            $ownLine && null === $slots->question,
            $flowIndent,
            $keyNode,
            $directValue,
        );
    }

    private static function documentMarker(string $yaml, int $offset): bool
    {
        return '-' === $yaml[$offset] && \ctype_space($yaml[$offset + 3] ?? "\n") && '---' === \substr($yaml, $offset, length: 3);
    }

    private static function root(string $yaml, MermaidStatement $statement): void
    {
        // Root properties cannot decorate the first compact implicit key.
        // They must own their line or precede an explicit collection node.
        if (1 === \preg_match('/\A(?:\s|#[^\r\n]*(?:\r?\n|\z))*(?:---(?:\s|#[^\r\n]*(?:\r?\n|\z))*)?(?:(?:!<[^>\r\n]*>|![^\s,\[\]{}]*|&[^\s,\[\]{}]+)[\t ]+){1,2}'.self::KEY.'/', $yaml)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
    }

    private static function needsNormalization(string $yaml): bool
    {
        return (
            \str_contains($yaml, '!')
            || \str_contains($yaml, '&')
            || \str_contains($yaml, '[')
            || \substr_count($yaml, needle: '{') > 1
            || 1 === \preg_match('/:[\t \r\n]*(?:[&+?\-.0-9]|(?:true|false|null)\b)|:[^,\r\n}\]]*:[\t ]|\n[\t ]*\r?\n[\t ]+\S|:[\t \r\n]*#/i', $yaml)
        );
    }

    private static function key(string $yaml, int $offset): bool
    {
        return 1 === \preg_match('/\G(?:!<[^>\r\n]*>|![^\s,\[\]{}]*)[\t ]+'.self::KEY.'/', $yaml, offset: $offset);
    }

    /**
     * @param array<string, string> $handles
     * @return array{string, int} normalized tag/anchor header and first unconsumed byte
     */
    // @mago-expect lint:no-boolean-flag-parameter -- These flags record the existing anchor and whether properties own their line.
    private static function header(
        string $yaml,
        int $offset,
        bool $anchored,
        MermaidStatement $statement,
        array $handles,
        int $indent,
        bool $ownLine,
        ?int $flowIndent,
        bool $keyNode,
        bool $directValue,
    ): array {
        $match = [];
        \preg_match('/\G(!<[^>\r\n]*>|![^\s,\[\]{}]*(?=[\s,\[\]{}]|$))/', $yaml, $match, offset: $offset);
        /** @var array{string, string} $match The short alternative always matches the known opening !. */
        $tag = $match[1];
        $name = self::name($tag, $statement, $handles);
        $end = $offset + \strlen($tag);
        if ($end < \strlen($yaml) && !\str_starts_with($tag, '!<') && !\ctype_space($yaml[$end])) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $anchor = [];
        $separation = \str_starts_with($tag, '!<') ? '[\t ]*' : '[\t ]+';
        if (1 === \preg_match('/\G'.$separation.'(&[^\s,\[\]{}]+)[\t ]*/', $yaml, $anchor, offset: $end)) {
            if ($anchored) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $end += \strlen($anchor[0]);
        }
        $next = $end;
        while (\in_array($yaml[$next] ?? '', ["\t", ' '], strict: true)) {
            ++$next;
        }
        if (\in_array($yaml[$next] ?? '', ['!', '&'], strict: true)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        if ('*' === ($yaml[$next] ?? '')) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $nonSpecific = '!' === $tag;
        if ($keyNode) {
            return [self::properties($nonSpecific ? '! ' : '!!'.$name.' ', $anchor[1] ?? null), $end];
        }
        $body = $next;
        $bodyKind = null;
        if (null !== $flowIndent) {
            [$bodyKind, $body] = SequenceYamlNode::flow($yaml, $next, $flowIndent, $statement);
        }

        // Symfony's explicit !!str scanner does not shield flow punctuation inside
        // quotes. Quoted/block scalars and ordinary string keys need no cast.
        $alreadyString = \in_array($yaml[$body] ?? '', ['"', "'", '|', '>'], strict: true);
        $tag = 'str' === $name && $alreadyString ? '' : '!!'.$name;
        if ($nonSpecific) {
            [$kind] = SequenceYamlNode::read($yaml, $next, $flowIndent ?? $indent, null === $flowIndent && $ownLine);
            if (\in_array($kind, ['seq', 'map'], strict: true)) {
                $tag = '';
            }
            if ('empty' === $kind) {
                $name = 'null';
                $bodyKind = 'empty';
            }
        }
        if (!$nonSpecific && null !== $bodyKind && 'empty' !== $bodyKind && $bodyKind !== (\in_array($name, ['seq', 'map'], strict: true) ? $name : 'str')) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        if (\in_array($name, ['seq', 'map'], strict: true)) {
            [$kind] = SequenceYamlNode::read($yaml, $next, $flowIndent ?? $indent, null === $flowIndent && $ownLine);
            if (!\in_array($kind, [$name, 'empty'], strict: true)) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $empty = 'seq' === $name ? '[]' : '{}';
            $tag = 'empty' === $kind ? $empty : '';
        }
        if (
            \in_array($name, ['null', 'bool', 'int', 'float'], strict: true)
            || ('str' === $name && (null !== $flowIndent || $directValue) && !\in_array($yaml[$body] ?? '', ['[', '{', '|', '>'], strict: true))
        ) {
            [$tag, $end] = self::scalarTag($yaml, $body, $next, $name, $bodyKind, $flowIndent, $indent, $statement);
        }

        return [self::properties($tag, $anchor[1] ?? null), $end];
    }

    /** @return array{string, int} */
    private static function scalarTag(string $yaml, int $body, int $next, string $name, ?string $bodyKind, ?int $flowIndent, int $indent, MermaidStatement $statement): array
    {
        [$content, $end] = 'empty' === $bodyKind ? [null, $body] : SequenceYamlScalar::tagged($yaml, $body, $statement, flowIndent: $flowIndent, blockIndent: $indent);
        $tag = (null === $content ? 'null' : MermaidYamlPrimitive::canonical($content)).' ';
        if ('str' !== $name && !MermaidYamlPrimitive::accepts($name, $content)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        if ('str' === $name) {
            $tag = \json_encode(MermaidUtf16::display($content ?? ''), flags: \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        }
        $tag .= \preg_replace('/[^\r\n]/', replacement: '', subject: \substr($yaml, $next, $end - $next)) ?? '';

        return [$tag, $end];
    }

    private static function flowKey(string $yaml, int $offset, SequenceYamlSlots $slots, int $indent, MermaidStatement $statement): ?SequenceYamlKey
    {
        if (null === $slots->question && !\in_array($slots->previous, ['[', '{', ','], strict: true)) {
            return null;
        }
        $node = SequenceYamlKeyNode::read($yaml, $offset, $indent, $statement);

        return SequenceYamlKeys::flow($yaml, $offset, $node->end, $slots->collection, $slots->previous, $slots->question, $statement);
    }

    private static function properties(string $tag, ?string $anchor): string
    {
        if (null === $anchor) {
            return $tag;
        }
        $tag = $anchor.' '.$tag;

        return \ctype_space($tag[-1]) ? $tag : $tag.' ';
    }

    /** @param array<string, string> $handles */
    private static function name(string $tag, MermaidStatement $statement, array $handles): string
    {
        $short = [];
        $uri = \str_starts_with($tag, '!<') && \str_ends_with($tag, '>') ? \rawurldecode(\substr($tag, offset: 2, length: -1)) : '';
        if (1 === \preg_match('/^(![^!]*!|!)([^!]*)$/D', $tag, $short) && \array_key_exists($short[1], $handles)) {
            $uri = $handles[$short[1]].\rawurldecode($short[2]);
            $tag = '';
        }
        $name = match (true) {
            '!' === $tag => 'str',
            \str_starts_with($tag, '!!') => \rawurldecode(\substr($tag, offset: 2)),
            \str_starts_with($uri, self::URI) => \substr($uri, offset: \strlen(self::URI)),
            default => '',
        };
        if (!\in_array($name, ['str', 'null', 'bool', 'int', 'float', 'seq', 'map'], strict: true)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return $name;
    }
}
