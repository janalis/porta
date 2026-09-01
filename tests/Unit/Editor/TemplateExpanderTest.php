<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\TemplateExpander;

final class TemplateExpanderTest extends TestCase
{
    /** @var array<string, string> */
    private const array TEMPLATES = [
        'fore' => 'FOREACH',
        'forek' => 'FOREACH_KEYED',
        '.if' => 'if ({{expr}}) {}',
        '.ifn' => 'if (null === {{expr}}) {}',
    ];

    /** @return array{consume: int, snippet: string}|null */
    private function at(string $line): ?array
    {
        return TemplateExpander::at($line, \strlen($line), self::TEMPLATES);
    }

    public function testAPlainAbbreviationExpandsAndConsumesExactlyItself(): void
    {
        $this->assertSame(['consume' => 4, 'snippet' => 'FOREACH'], $this->at('    fore'));
    }

    /**
     * The longer abbreviation must win, or it could never be typed: `forek`
     * ends with `fore`, so a shortest-first scan would eat it.
     */
    public function testTheLongestMatchingAbbreviationWins(): void
    {
        $this->assertSame(['consume' => 5, 'snippet' => 'FOREACH_KEYED'], $this->at('    forek'));
        // `.ifn` ends with `.if`'s own text too, so the same rule has to hold
        // for the postfix pair.
        $this->assertSame(['consume' => 6, 'snippet' => 'if (null === $x) {}'], $this->at('$x.ifn'));
    }

    /** @return \Generator<string, array{string}> */
    public static function nonAbbreviations(): \Generator
    {
        yield 'glued to a longer word' => ['beforek'];
        yield 'glued to an identifier' => ['$myfore'];
        yield 'part of a name' => ['doFore'];
        yield 'nothing there' => ['    '];
        yield 'not an abbreviation at all' => ['$total'];
    }

    #[DataProvider('nonAbbreviations')]
    public function testAnAbbreviationGluedToAWordIsNotOne(string $line): void
    {
        $this->assertNull($this->at($line));
    }

    /** @return \Generator<string, array{string, string, int}> */
    public static function postfixReceivers(): \Generator
    {
        yield 'a variable' => ['$items.if', '$items', 9];
        yield 'a property chain' => ['$this->rows.if', '$this->rows', 14];
        yield 'a static call' => ['Foo::bar.if', 'Foo::bar', 11];
        yield 'a namespaced name' => ['App\\Thing.if', 'App\\Thing', 12];
        yield 'a call' => ['count($x).if', 'count($x)', 12];
        yield 'an index' => ['$rows[0].if', '$rows[0]', 11];
    }

    #[DataProvider('postfixReceivers')]
    public function testAPostfixTemplateWrapsTheExpressionLeftOfItsDot(string $line, string $receiver, int $consume): void
    {
        $found = $this->at($line);

        $this->assertNotNull($found);
        $this->assertSame("if ({$receiver}) {}", $found['snippet']);
        $this->assertSame($consume, $found['consume'], 'the expression AND the abbreviation are both replaced');
    }

    /**
     * The expression is substituted before the body is parsed as a snippet, so
     * a `$1` inside the user's own code cannot become a tab stop.
     */
    public function testAnExpressionIsSubstitutedLiterallyAndNeverReadAsSnippetSyntax(): void
    {
        $found = TemplateExpander::at('$1.if', 5, self::TEMPLATES);

        $this->assertSame('if ($1) {}', $found['snippet'] ?? null);
    }

    /** With no expression before the dot there is nothing to wrap. */
    public function testABareDottedAbbreviationWithNoReceiverDoesNotExpand(): void
    {
        $this->assertNull($this->at('    .if'));
    }

    /** The caret is not always at the end of the line: only the text before it counts. */
    public function testOnlyTheTextBeforeTheCaretIsConsidered(): void
    {
        $this->assertSame(['consume' => 4, 'snippet' => 'FOREACH'], TemplateExpander::at('fore rest of line', 4, self::TEMPLATES));
        $this->assertNull(TemplateExpander::at('fore rest of line', 6, self::TEMPLATES), 'the caret is past the abbreviation');
    }

    public function testALanguageWithNoTemplatesExpandsNothing(): void
    {
        $this->assertNull(TemplateExpander::at('fore', 4, []));
    }
}
