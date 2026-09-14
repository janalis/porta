<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Highlight\TempestBackend;
use Tempest\Highlight\Highlighter;

/**
 * Classifies which byte ranges of a source file are comments or string literals,
 * using Tempest's real tokenizer via {@see CommentStringTheme}. The whole file is
 * parsed in one pass (so multi-line comments/docblocks and heredocs classify
 * correctly), then the sentinel markers are walked off to recover the spans in
 * the original byte coordinates.
 *
 * The tokenizer comes from {@see TempestBackend} so search sees the same house
 * grammars the editor does — before that was shared, this built a bare
 * Highlighter and a PHP docblock or a `tsconfig.json` classified differently
 * here than it rendered in the editor.
 */
final readonly class TokenClassifier
{
    private Highlighter $highlighter;
    /** @var \Closure(string, string): string */
    private \Closure $parser;

    /**
     * @param (\Closure(string, string): string)|null $parser  (content, language) => marked output;
     *                                                          injectable so tests can drive the walker directly
     * @param TokenSpanProvider|null                  $plugins plugin-supplied classifiers, asked first
     */
    public function __construct(
        ?\Closure $parser = null,
        private ?TokenSpanProvider $plugins = null,
    ) {
        $this->highlighter = TempestBackend::newHighlighter(new CommentStringTheme());
        $this->parser = $parser ?? $this->highlighter->parse(...);
    }

    /**
     * The comment/string byte spans of $content in $language, as `[start, end)`
     * offsets. Returns no spans if the highlighter fails.
     *
     * @return list<array{start: int, end: int}>
     */
    public function commentStringSpans(string $content, string $language): array
    {
        $plugin = $this->pluginSpans($content, $language);
        if (null !== $plugin) {
            return $plugin;
        }

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
            $pair = \substr($marked, $i, length: 2);
            if (CommentStringTheme::OPEN === $pair) {
                if (0 === $depth) {
                    $spanStart = $sourceOffset;
                }
                ++$depth;
                $i += 2;

                continue;
            }
            if (CommentStringTheme::CLOSE === $pair) {
                --$depth;
                if (0 === $depth) {
                    $spans[] = ['start' => $spanStart, 'end' => $sourceOffset];
                }
                $i += 2;

                continue;
            }
            ++$sourceOffset;
            ++$i;
        }

        return $spans;
    }

    /**
     * A plugin classifier's answer, or null when none claims the language or one
     * throws — a faulting plugin must never break search, it just declines.
     *
     * @return list<array{start: int, end: int}>|null
     */
    private function pluginSpans(string $content, string $language): ?array
    {
        if (null === $this->plugins) {
            return null;
        }

        try {
            return $this->plugins->commentStringSpans($content, $language);
        } catch (\Throwable) {
            return null;
        }
    }
}
