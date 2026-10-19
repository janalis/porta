<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\MermaidMarkdown;

/** Markdown string labels against the pinned HTML label path (fixtures/mermaid/labels/markdown-reference.json). */
final class MermaidMarkdownTest extends TestCase
{
    /** @param list<list<array{string, bool, bool}>> $expected */
    // @mago-expect lint:no-boolean-flag-parameter -- Whether the pinned variant auto-wraps is the case's one switch.
    #[DataProvider('cases')]
    public function testLinesAndRunsMatchThePinnedBuild(string $label, bool $wrap, array $expected): void
    {
        $markdown = MermaidMarkdown::parse($label);
        $markdown = $wrap ? $markdown->wrap(120.0) : $markdown;
        $actual = \array_map(static fn (array $runs): array => self::trimmed(\array_map(static fn (array $run): array => [
            $run[0],
            \in_array($run[1], [GraphFont::Bold, GraphFont::BoldItalic], strict: true),
            \in_array($run[1], [GraphFont::Italic, GraphFont::BoldItalic], strict: true),
        ], $runs)), $markdown->lines);
        $this->assertSame($expected, $actual);
    }

    /** @return iterable<string, array{string, bool, list<list<array{string, bool, bool}>>}> */
    public static function cases(): iterable
    {
        /** @var array{cases: list<array{variant: string, label: string, lines: list<list<array{text: string, bold: bool, italic: bool}>>}>} $reference */
        $reference = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/labels/markdown-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['cases'] as $case) {
            if ('svg' === $case['variant']) {
                continue; // Native labels follow the default HTML label path.
            }
            // Without auto-wrap pinned writes non-breaking spaces, which draw as spaces.
            $lines = \array_map(static fn (array $line): array => self::trimmed(\array_map(static fn (array $run): array => [
                \str_replace("\u{a0}", replace: ' ', subject: $run['text']),
                $run['bold'],
                $run['italic'],
            ], $line)), $case['lines']);
            yield $case['variant'].' '.$case['label'] => [$case['label'], 'html' === $case['variant'], $lines];
        }
    }

    public function testOnlyBacktickQuotedStringsAreMarkdown(): void
    {
        $this->assertSame('**a**', MermaidMarkdown::body('"`**a**`"'));
        $this->assertSame('b', MermaidMarkdown::body('`b`'));
        $this->assertNull(MermaidMarkdown::body('"plain"'));
        $this->assertNull(MermaidMarkdown::body('`'));
        $this->assertSame("one\ntwo", MermaidMarkdown::parse("  one\n  two")->plain());
        $this->assertSame('snake_case_name and *', MermaidMarkdown::parse('snake_case_name and *')->plain());
        $this->assertSame(
            [
                ['a ', GraphFont::Regular],
                ['b',  GraphFont::Italic],
            ],
            MermaidMarkdown::parse('a _b_')->lines[0],
        );
        // CommonMark: a single closer takes one of two openers, and delimiters inside a pair stay text.
        $this->assertSame(
            [
                ['*',   GraphFont::Regular],
                ['foo', GraphFont::Italic],
            ],
            MermaidMarkdown::parse('**foo*')->lines[0],
        );
        $this->assertSame(
            [
                ['foo _bar', GraphFont::Italic],
                [' baz_',    GraphFont::Regular],
            ],
            MermaidMarkdown::parse('*foo _bar* baz_')->lines[0],
        );
    }

    /**
     * Trailing spaces end a pinned line without drawing anything.
     *
     * @param list<array{string, bool, bool}> $runs
     * @return list<array{string, bool, bool}>
     */
    private static function trimmed(array $runs): array
    {
        $last = \array_key_last($runs);
        if (null !== $last) {
            $runs[$last][0] = \rtrim($runs[$last][0], characters: ' ');
        }

        return \array_values(\array_filter($runs, static fn (array $run): bool => '' !== $run[0]));
    }
}
