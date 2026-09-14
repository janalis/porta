<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\StatementSyntax;
use Porta\Support\LanguageMap;

/**
 * The table behind `Ctrl+Shift+Enter`, gated the same way
 * {@see \Porta\Tests\Unit\Editor\CommentSyntaxTest} gates comments: every
 * grammar Porta can open is a row, so adding a language fails here until
 * someone says how it ends a statement.
 *
 * The difference from comments is worth stating, because it changes what the
 * gate is FOR. Every language has some comment form, so a `null` there was
 * always an omission. Most languages have no statement to complete at all —
 * JSON, Markdown, TOML, `.env` — so `null` here is a real answer, and the gate
 * exists to make it a written-down one rather than a gap that looks the same.
 */
final class StatementSyntaxTest extends TestCase
{
    public function testEveryGrammarThatCanBeOpenedHasADecision(): void
    {
        $undecided = [];
        foreach (LanguageMap::grammars() as $grammar) {
            if (\array_key_exists($grammar, self::rawTable())) {
                continue;
            }
            $undecided[] = $grammar;
        }

        $this->assertSame([], $undecided, 'StatementSyntax has no entry for: '.\implode(', ', $undecided));
    }

    public function testTheTableNamesNoGrammarThatCannotBeOpened(): void
    {
        $unreachable = \array_values(\array_diff(\array_keys(self::rawTable()), LanguageMap::grammars()));

        $this->assertSame([], $unreachable, 'StatementSyntax names grammars LanguageMap cannot produce: '.\implode(', ', $unreachable));
    }

    /**
     * A language with block KEYWORDS must have somewhere to put the block.
     *
     * Keywords without an opener is the typo that does nothing at all: the
     * verb would recognise `if` and then have no brace to append, silently
     * falling through to the terminator branch and writing `if (x);`.
     */
    public function testNoLanguageListsBlockKeywordsWithoutABlockToOpen(): void
    {
        $stranded = [];
        foreach (self::rawTable() as $grammar => [, $block, $keywords]) {
            if ([] === $keywords || null !== $block) {
                continue;
            }
            $stranded[] = $grammar;
        }

        $this->assertSame([], $stranded, 'block keywords with no block: '.\implode(', ', $stranded));
    }

    /**
     * The opener carries its own spacing, so the table is where the difference
     * between `if (x) {` and `if x:` lives.
     */
    public function testTheOpenerIsStoredExactlyAsItIsAppended(): void
    {
        $this->assertSame([' {', '}'], StatementSyntax::forGrammar('php')?->block, 'a brace is spaced off the condition');
        $this->assertSame([':', null], StatementSyntax::forGrammar('python')?->block, 'a colon binds to it, and closes nothing');
    }

    public function testTheShapesAndTheAbsentOnes(): void
    {
        $php = StatementSyntax::forGrammar('php');
        $this->assertSame(';', $php?->terminator);

        // Go's compiler inserts semicolons and gofmt removes them, so writing
        // one is the opposite of what the language wants — braces only.
        $go = StatementSyntax::forGrammar('go');
        $this->assertNull($go?->terminator);
        $this->assertSame([' {', '}'], $go?->block);

        // Python terminates nothing.
        $this->assertNull(StatementSyntax::forGrammar('python')?->terminator);

        // CSS ends declarations but its `{` follows a selector, which is not a
        // keyword and cannot be recognised without parsing.
        $css = StatementSyntax::forGrammar('css');
        $this->assertSame(';', $css?->terminator);
        $this->assertNull($css?->block);

        // Decided, not forgotten.
        $this->assertNull(StatementSyntax::forGrammar('json'));
        $this->assertNull(StatementSyntax::forGrammar('markdown'));
        $this->assertNull(StatementSyntax::forGrammar('yaml'));
        $this->assertNull(StatementSyntax::forGrammar('brainfuck'));
        $this->assertNull(StatementSyntax::forGrammar(null));
    }

    /**
     * The keyword match is on the line's FIRST word, at a word boundary.
     *
     * `iffy(` begins with the letters of `if` and is a call; `$x = fn() => 1`
     * mentions no keyword at its head. A `str_contains` would brace both.
     */
    public function testOnlyTheLeadingWordOpensABlock(): void
    {
        $php = StatementSyntax::forGrammar('php');
        \assert($php instanceof StatementSyntax, description: 'php is in the table');

        $this->assertTrue($php->opensABlock('if ($x)'));
        $this->assertTrue($php->opensABlock('    foreach ($a as $b)'), 'indented, still the first word');
        $this->assertTrue($php->opensABlock('IF ($x)'), 'case-insensitively');

        $this->assertFalse($php->opensABlock('iffy($x)'), 'a call whose name starts with a keyword');
        // The case the anchor exists for, and it is not hypothetical: a line
        // that CLOSES the previous block and opens the next one already has
        // its brace, and an unanchored match would find `else` and add a
        // second — `} else { {`.
        $this->assertFalse($php->opensABlock('} else {'), 'a keyword after a closing brace is not the head');
        $this->assertFalse($php->opensABlock('    } catch ($e) {'), 'indented, and still not the head');
        $this->assertFalse($php->opensABlock('$ok = $x ? 1 : 2'), 'no keyword at the head');
        $this->assertFalse($php->opensABlock('return $this->if()'), 'a keyword later in the line is not the head');
        $this->assertFalse($php->opensABlock(''), 'nothing at all');
    }

    /**
     * @return array<string, array{0: string|null, 1: array{0: string, 1: string|null}|null, 2: list<string>}>
     */
    private static function rawTable(): array
    {
        // @mago-expect lint:inline-variable-return -- getConstant() returns
        // mixed, and the @var on the assignment is what gives the rows a shape.
        /** @var array<string, array{0: string|null, 1: array{0: string, 1: string|null}|null, 2: list<string>}> $constant */
        $constant = new \ReflectionClass(StatementSyntax::class)->getConstant('SYNTAX');

        return $constant;
    }
}
