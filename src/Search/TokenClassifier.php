<?php

declare(strict_types=1);

namespace Porta\Search;

use Tempest\Highlight\Highlighter;

/**
 * Classifies which byte ranges of a source file are comments or string literals,
 * using Tempest's real tokenizer via {@see CommentStringTheme}. The whole file is
 * parsed in one pass (so multi-line comments/docblocks and heredocs classify
 * correctly), then the sentinel markers are walked off to recover the spans in
 * the original byte coordinates.
 */
final class TokenClassifier
{
    private readonly Highlighter $highlighter;
    /** @var \Closure(string, string): string */
    private readonly \Closure $parser;

    /**
     * @param (\Closure(string, string): string)|null $parser (content, language) => marked output;
     *                                                         injectable so tests can drive the walker directly
     */
    public function __construct(?\Closure $parser = null)
    {
        $this->highlighter = new Highlighter(new CommentStringTheme());
        $this->parser = $parser ?? fn (string $content, string $language): string => $this->highlighter->parse($content, $language);
    }

    /**
     * The comment/string byte spans of $content in $language, as `[start, end)`
     * offsets. Returns no spans if the highlighter fails.
     *
     * @return list<array{start: int, end: int}>
     */
    public function commentStringSpans(string $content, string $language): array
    {
        try {
            $marked = ($this->parser)($content, $language);
        } catch (\Throwable) {
            return []; // a highlighter failure means "classify nothing"
        }

        $spans = [];
        $depth = 0;
        $sourceOffset = 0;
        $spanStart = 0;
        $length = \strlen($marked);
        for ($i = 0; $i < $length;) {
            $pair = substr($marked, $i, 2);
            if (CommentStringTheme::OPEN === $pair) {
                if (0 === $depth) {
                    $spanStart = $sourceOffset;
                }
                ++$depth;
                $i += 2;
            } elseif (CommentStringTheme::CLOSE === $pair) {
                --$depth;
                if (0 === $depth) {
                    $spans[] = ['start' => $spanStart, 'end' => $sourceOffset];
                }
                $i += 2;
            } else {
                ++$sourceOffset;
                ++$i;
            }
        }

        return $spans;
    }
}
