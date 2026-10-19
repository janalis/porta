<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Declaration tokens retain source positions; quoted labels and curve bodies own newlines. */
final readonly class RadarLexer
{
    private const string ID = '\w(?:[-\w]*\w)?';
    private const string STRING = '"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\'';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        $named = self::ID.'(?:\s*\[\s*(?:'.self::STRING.')\s*\])?';
        $patterns = [
            '~\Gradar-beta[\t ]*:?(?=\s|$)~i',
            '~\G(?:title\b[^\r\n]*|accTitle[\t ]*:[^\r\n]*|accDescr[\t ]*:[^\r\n]*|accDescr\s*\{[^}]*\})~i',
            '~\Gaxis\s+'.$named.'(?:[\t ]*,\s*'.$named.')*~u',
            '~\Gcurve\s+'.$named.'\s*\{[^{}]*\}(?:\s*,\s*'.$named.'\s*\{[^{}]*\})*~u',
            '~\G(?:showLegend|ticks|max|min|graticule)\s+[^\s,]+(?:[\t ]*,[\t ]*(?:showLegend|ticks|max|min|graticule)\s+[^\s,]+)*~',
        ];
        yield from new MermaidPatternLexer($this->source, $patterns, error: 'Invalid radar declaration or unclosed label/body')->statements();
    }
}
