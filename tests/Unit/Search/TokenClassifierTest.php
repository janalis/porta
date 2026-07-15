<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\CommentStringTheme;
use Porta\Search\TokenClassifier;

final class TokenClassifierTest extends TestCase
{
    public function testWalksSentinelMarkersIntoSpans(): void
    {
        $open = CommentStringTheme::OPEN;
        $close = CommentStringTheme::CLOSE;
        // Marked output "ab<cd>ef" → 'cd' spans source offsets [2, 4).
        $classifier = new TokenClassifier(static fn (string $content, string $language): string => "ab{$open}cd{$close}ef");

        $this->assertSame([['start' => 2, 'end' => 4]], $classifier->commentStringSpans('abcdef', 'php'));
    }

    public function testNestedMarkersCollapseToASingleSpan(): void
    {
        $open = CommentStringTheme::OPEN;
        $close = CommentStringTheme::CLOSE;
        // Outer marks 'bcd', inner marks 'c' → one span [1, 4).
        $classifier = new TokenClassifier(static fn (string $c, string $l): string => "a{$open}b{$open}c{$close}d{$close}e");

        $this->assertSame([['start' => 1, 'end' => 4]], $classifier->commentStringSpans('abcde', 'php'));
    }

    public function testHighlighterFailureYieldsNoSpans(): void
    {
        $classifier = new TokenClassifier(static function (string $c, string $l): string {
            throw new \RuntimeException('boom');
        });

        $this->assertSame([], $classifier->commentStringSpans('anything', 'php'));
    }

    public function testRealPhpClassificationCoversCommentsAndStrings(): void
    {
        $classifier = new TokenClassifier();
        $src = "<?php\n\$needle = 1; // needle\n\$s = 'needle';\nfunction needle() {}\n";
        $spans = $classifier->commentStringSpans($src, 'php');

        $inside = static function (int $offset) use ($spans): bool {
            foreach ($spans as $span) {
                if ($offset >= $span['start'] && $offset < $span['end']) {
                    return true;
                }
            }

            return false;
        };

        // The four occurrences of "needle": variable (code), comment, string, function name (code).
        $offsets = [];
        $at = 0;
        while (false !== ($at = strpos($src, needle: 'needle', offset: $at))) {
            $offsets[] = $at;
            $at += 6;
        }

        $this->assertFalse($inside($offsets[0]), '$needle is code');
        $this->assertTrue($inside($offsets[1]), 'the comment occurrence is classified');
        $this->assertTrue($inside($offsets[2]), 'the string occurrence is classified');
        $this->assertFalse($inside($offsets[3]), 'the function name is code');
    }

    public function testMultiLineDocblockClassifiesAcrossLines(): void
    {
        $classifier = new TokenClassifier();
        $src = "<?php\n/**\n * needle in a docblock\n */\nfunction f() {}\n";
        $spans = $classifier->commentStringSpans($src, 'php');

        $offset = (int) strpos($src, needle: 'needle');
        $inside = false;
        foreach ($spans as $span) {
            if (!($offset >= $span['start'] && $offset < $span['end'])) {
                continue;
            }

            $inside = true;
        }

        $this->assertTrue($inside, 'a match inside a multi-line docblock is classified as a comment');
    }
}
