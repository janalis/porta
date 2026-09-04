<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The house JSON grammar — see {@see \Porta\Highlight\Json\JsonLanguage} for
 * the three gaps in Tempest's built-in one that these cover.
 */
final class JsonHighlightTest extends TestCase
{
    private const string KEY = "\x1b[38;5;176m"; // purple (KEYWORD/LITERAL)
    private const string STRING = "\x1b[38;5;114m"; // green (VALUE)
    private const string NUMBER = "\x1b[38;5;215m"; // orange
    private const string PUNCT = "\x1b[38;5;117m"; // light blue (PROPERTY)
    private const string COMMENT = "\x1b[38;5;243m"; // gray

    public function testResolvesJsonFromTheExtension(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('composer.json');

        $this->assertSame('json', $highlighter->getLanguage());
    }

    /**
     * The regression this class exists for: the built-in `".*?"` rule ended the
     * string token at the escaped quote, so the tail of the line rendered with
     * string and code coloring swapped.
     */
    public function testEscapedQuoteKeepsTheStringOneToken(): void
    {
        $line = $this->highlight('{"msg": "she said \\"hi\\" and left", "next": "ok"}');

        $this->assertStringContainsString(self::STRING.'"she said \\"hi\\" and left"', $line);
        $this->assertStringContainsString(self::KEY.'"next"', $line);
        $this->assertStringContainsString(self::STRING.'"ok"', $line);
    }

    public function testEscapedBackslashAtStringEndKeepsQuoteParity(): void
    {
        $line = $this->highlight('{"p": "C:\\\\", "next": "ok"}');

        $this->assertStringContainsString(self::STRING.'"C:\\\\"', $line);
        $this->assertStringContainsString(self::KEY.'"next"', $line);
    }

    /** Keys the built-in `[\w\-]` rule missed, which took the value color. */
    public function testKeysWithPunctuationColorAsKeysNotValues(): void
    {
        $line = $this->highlight('{"$schema": 1, "@scope/pkg": 2, "a.b c": 3}');

        $this->assertStringContainsString(self::KEY.'"$schema"', $line);
        $this->assertStringContainsString(self::KEY.'"@scope/pkg"', $line);
        $this->assertStringContainsString(self::KEY.'"a.b c"', $line);
        $this->assertStringNotContainsString(self::STRING, $line, 'no key took the string color');
    }

    public function testNumbersAndLiteralsAreColored(): void
    {
        $line = $this->highlight('{"n": [0, -1, 3.14, 1e10, -2.5E-3], "b": true, "z": null}');

        foreach (['0', '-1', '3.14', '1e10', '-2.5E-3'] as $number) {
            $this->assertStringContainsString(self::NUMBER.$number, $line);
        }

        $this->assertStringContainsString(self::KEY.'true', $line);
        $this->assertStringContainsString(self::KEY.'null', $line);
        $this->assertStringContainsString(self::PUNCT.'[', $line);
    }

    /** Number/literal/brace rules must not fire inside an already-matched string. */
    public function testStringContentIsNeverRetokenized(): void
    {
        $line = $this->highlight('{"v": "abc123 true null { } [ ]"}');

        $this->assertStringContainsString(self::STRING.'"abc123 true null { } [ ]"', $line);
        $this->assertStringNotContainsString(self::NUMBER, $line);
    }

    public function testWideAndRtlStringsSurviveIntact(): void
    {
        $line = $this->highlight('{"cjk": "中文字符", "he": "שלום"}');

        $this->assertStringContainsString(self::STRING.'"中文字符"', $line);
        $this->assertStringContainsString(self::STRING.'"שלום"', $line);
        $this->assertSame('{"cjk": "中文字符", "he": "שלום"}', AnsiUtils::stripAnsiCodes($line));
    }

    public function testMultiLineDocumentKeepsLineStructureAndPlainText(): void
    {
        $source = "{\n  \"a\": 1,\n  \"b\": \"two\"\n}";
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('t.json');

        $lines = $highlighter->highlight(explode("\n", $source), 1);

        $this->assertCount(4, $lines);
        $this->assertSame($source, implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines)));
        $this->assertStringContainsString(self::KEY.'"a"', $lines[1]);
        $this->assertStringContainsString(self::NUMBER.'1', $lines[1]);
        $this->assertStringContainsString(self::STRING.'"two"', $lines[2]);
    }

    /** A key whose colon sits on the next line still reads as a key. */
    public function testKeyIsRecognizedAcrossALineBreak(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('t.json');

        $lines = $highlighter->highlight(explode("\n", string: "{\n  \"a\"\n  : 1\n}"), 1);

        $this->assertStringContainsString(self::KEY.'"a"', $lines[1]);
    }

    public function testStrictJsonDoesNotTreatSlashesAsComments(): void
    {
        $line = $this->highlight('{"a": 1} // trailing');

        $this->assertStringNotContainsString(self::COMMENT, $line, 'plain .json has no comment rule');
    }

    public function testJsoncColorsLineAndBlockComments(): void
    {
        $line = $this->highlight('{ /* head */ "a": 1 // tail', file: 'tsconfig.json');

        $this->assertStringContainsString(self::COMMENT.'/* head */', $line);
        $this->assertStringContainsString(self::COMMENT.'// tail', $line);
        $this->assertStringContainsString(self::KEY.'"a"', $line);
    }

    /**
     * Both directions of the overlap rule, which is positional: the comment
     * starts before the string so it swallows it, and the string starts before
     * the `//` so the URL stays one value.
     */
    public function testCommentAndStringOverlapResolvesByPosition(): void
    {
        $commented = $this->highlight('{ // "a": 1', file: 'a.jsonc');
        $this->assertStringContainsString(self::COMMENT.'// "a": 1', $commented);
        $this->assertStringNotContainsString(self::KEY, $commented, 'the key is inside the comment');

        $url = $this->highlight('{"u": "http://x/y"}', file: 'a.jsonc');
        $this->assertStringContainsString(self::STRING.'"http://x/y"', $url);
        $this->assertStringNotContainsString(self::COMMENT, $url, 'the // is inside the string');
    }

    /**
     * The diff/injection marker patterns every language carries — both the
     * addition-start and addition-end tokens must be present for the pair to
     * be recognized and stripped at all; a lone marker is left as-is.
     */
    /**
     * Tempest's `Base` language treats `{+ … +}` as diff-addition markers and
     * DELETES them, in every language it ships. Nothing in Porta ever wanted
     * that — the git diff view has its own renderer — and in an editor a line
     * that renders four characters shorter than the file is a bug however
     * pretty it is. `CodeHighlighter` renders such a line from the file.
     */
    public function testDiffMarkersAreNotDeletedFromTheLineTheyAppearOn(): void
    {
        $this->assertSame('{+ x +}', AnsiUtils::stripAnsiCodes($this->highlight('{+ x +}')));
    }

    public function testTheSameHoldsForJsonc(): void
    {
        $this->assertSame('{+ x +}', AnsiUtils::stripAnsiCodes($this->highlight('{+ x +}', file: 'a.jsonc')));
    }

    public function testJsoncBlockCommentSpansLines(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('a.jsonc');

        $lines = $highlighter->highlight(explode("\n", string: "{\n  /* one\n     two */\n  \"a\": 1\n}"), 1);

        $this->assertStringContainsString(self::COMMENT, $lines[1]);
        $this->assertStringContainsString(self::COMMENT, $lines[2], 'color re-opened on the continuation line');
        $this->assertStringContainsString(self::KEY.'"a"', $lines[3]);
    }

    private function highlight(string $source, string $file = 't.json'): string
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile($file);

        return $highlighter->highlight([$source], 1)[0];
    }
}
