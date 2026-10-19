<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** All four pinned notations share source positions and cooperative token limits. */
final readonly class RailroadLexer
{
    public function __construct(
        private string $source,
    ) {}

    public static function header(string $source): ?string
    {
        $match = [];

        return 1 === \preg_match('/\A(?:\s++|%%[^\r\n]*|\#[^\r\n]*|\/\*[\s\S]*?\*\/|\(\*[\s\S]*?\*\))*+(railroad(?:-(?:ebnf|abnf|peg))?-beta)(?=%%|\s|$)/', $source, $match) ? $match[1] : null;
    }

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $offset = 0;
        $line = 0;
        $column = 0;
        $mode = \explode('-', self::header($this->source) ?? 'railroad-beta')[1];
        $count = 0;
        $length = \strlen($this->source);
        while ($offset < $length) {
            $at = new MermaidStatement('', $offset, $line, $column);
            $match = [];
            $block = \in_array($mode, ['beta', 'ebnf'], strict: true) ? '|\/\*[\s\S]*?\*\/' : '';
            $comment = match ($mode) {
                'ebnf' => '|\(\*[\s\S]*?\*\)',
                'peg' => '|\#[^\r\n]*',
                default => '',
            };
            $hidden = 1 === \preg_match('/\G(?:\s+|%%[^\r\n]*'.$block.$comment.')/', $this->source, $match, offset: $offset);
            $string = 'abnf' === $mode ? '"[^"]*"' : RailroadText::STRING;
            $special = 'ebnf' === $mode ? '|\?(?=[^?;]*[^?\s;][^?;]*\?)[^?;]*\?' : '';
            if (
                !$hidden
                && 1 !== \preg_match(
                    '/\G(?:railroad(?:-(?:ebnf|abnf|peg))?-beta(?=%%|\s|$)|title(?:[\t ][^\r\n]*?(?=%%)|[\t ][^\r\n]*|)|accTitle[\t ]*:(?:[^\r\n]*?(?=%%)|[^\r\n]*)|accDescr(?:[\t ]*:([^\r\n]*?(?=%%)|[^\r\n]*)|\s*{[^}]*})|'
                    .$string
                    .$special
                    .'|%[xXdDbB][0-9A-Fa-f]+(?:-[0-9A-Fa-f]+|\.[0-9A-Fa-f]+)*|[0-9]*\*[0-9]*|[0-9]+|[A-Za-z_][A-Za-z0-9_-]*|::=|<-|[=;,()\[\]{}|?+\-\/&!.])/',
                    $this->source,
                    $match,
                    offset: $offset,
                )
            ) {
                throw $at->error('Unexpected railroad token');
            }
            $text = $match[0];
            if (++$count > 131_072 || \strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $at->error('Railroad token or work limit exceeded');
            }
            yield new MermaidStatement($hidden ? '' : $text, $offset, $line, $column);
            $line += (int) \preg_match_all('/\r\n|\r|\n/', $text);
            $last = \strrpos(\str_replace("\r", replace: "\n", subject: $text), needle: "\n");
            $column = false === $last ? $column + \mb_strlen($text) : \mb_strlen(\substr($text, $last + 1));
            $offset += \strlen($text);
        }
    }
}
