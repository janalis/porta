<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Streaming tokens keep the whitespace-insensitive grammar cancellable between frame fields. */
final readonly class EventModelingLexer
{
    public const string ID = '[_A-Za-z][A-Za-z0-9_]*';
    public const array TOP = ['entity', 'tf', 'timeframe', 'rf', 'resetframe', 'data', 'note', 'gwt'];
    public const array ROLES = ['rmo', 'readmodel', 'ui', 'cmd', 'command', 'evt', 'event', 'pcr', 'processor'];
    public const array DATA_TYPES = ['json', 'jsobj', 'figma', 'salt', 'uri', 'md', 'html', 'text'];

    public function __construct(
        private string $source,
    ) {}

    public static function detect(string $source): bool
    {
        return 1 === \preg_match('/\A(?:\s++|\/\*[\s\S]*?\*\/|\/\/[^\r\n]*|%%[^\r\n]*)*+eventmodeling\b/', $source);
    }

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $offset = 0;
        $line = 0;
        $column = 0;
        $length = \strlen($this->source);
        $count = 0;
        while ($offset < $length) {
            $statement = new MermaidStatement('', $offset, $line, $column);
            [$text, $hidden] = $this->token($statement);
            if (++$count > 131_072 || \strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Event-modeling token or work limit exceeded');
            }
            // Trivia yields too: large comment streams must still honor cancellation and work slices.
            yield new MermaidStatement($hidden ? '' : $text, $offset, $line, $column);
            $newlines = \substr_count($text, needle: "\n");
            $last = \strrpos($text, needle: "\n");
            $column = false === $last ? $column + \mb_strlen($text) : \mb_strlen(\substr($text, $last + 1));
            $line += $newlines;
            $offset += \strlen($text);
        }
    }

    /** @return array{string, bool} */
    private function token(MermaidStatement $statement): array
    {
        $offset = $statement->offset;
        $match = [];
        // Metadata follows EM_ID in the pinned lexer, so only an indented token can win over that ID.
        $metadata = '/\G[\t ]+(?:accDescr(?:[\t ]*:(?:[^\r\n]*?(?=%%)|[^\r\n]*)|\s*\{[^}]*\})|accTitle[\t ]*:(?:[^\r\n]*?(?=%%)|[^\r\n]*)|title(?:[\t ][^\r\n]*?(?=%%)|[\t ][^\r\n]*|))/';
        if (1 === \preg_match($metadata, $this->source, $match, offset: $offset)) {
            return [$match[0], false];
        }
        $hidden = 1 === \preg_match(
            '/\G(?:---[\t ]*\r?\n(?:[\S\s]*?\r?\n)?---(?:\r?\n|(?!\S))|%%\{[\S\s]*?\}%%(?:\r?\n|(?!\S))|\s+|%%[^\r\n]*|\/\/[^\r\n]*|\/\*[\s\S]*?\*\/)/',
            $this->source,
            $match,
            offset: $offset,
        );
        if (
            !$hidden
            && 1 !== \preg_match('/\G(?:'.self::ID.'|\d{1,3}|\{(.*)\}|"(.*)"|\'(.*)\'|\{[\t ]*\r?\n(?:[\S\s]*?\r?\n)?\}(?:\r?\n|(?!\S))|->>|\[\[|\]\]|[.`])/', $this->source, $match, offset: $offset)
        ) {
            throw $statement->error('Unexpected event-modeling token');
        }

        return [$match[0], $hidden];
    }
}
