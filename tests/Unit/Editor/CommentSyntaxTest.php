<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CommentSyntax;
use Porta\Support\LanguageMap;
use Porta\Tests\Support\Probe;

/**
 * The first test here is the one that matters: it is the gate that stops
 * `Ctrl+/` from ever being a dead key again.
 *
 * It was dead in thirty-one of the forty-five grammars Porta can colour, and
 * nothing noticed for the whole life of the feature, because the old answer was
 * a fourteen-arm match with a `default => null` — a shape in which "this
 * language has no comment" and "nobody has got round to this language" are the
 * same value. Every gate passed. The verb simply did nothing, silently, in
 * Twig, HTML, Markdown, XML, Go, Rust, Java, Lua and twenty-three others.
 *
 * So the table lists every grammar EXPLICITLY, including the two that have no
 * comment form, and this test asserts that the table and
 * {@see LanguageMap::grammars()} agree in both directions. Adding a language to
 * the map now fails here until someone says how it comments — which is a
 * question they can answer, unlike "did you remember to also edit a match arm
 * in a different namespace".
 */
final class CommentSyntaxTest extends TestCase
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

        $this->assertSame([], $undecided, 'CommentSyntax has no entry for: '.\implode(', ', $undecided));
    }

    /**
     * The other direction, which is the one a REMOVAL breaks: deleting `.hs`
     * from the language map would leave a haskell row here that no file can
     * reach, and a table nobody can reach is where wrong entries go to live.
     */
    public function testTheTableNamesNoGrammarThatCannotBeOpened(): void
    {
        $unreachable = \array_values(\array_diff(\array_keys(self::rawTable()), LanguageMap::grammars()));

        $this->assertSame([], $unreachable, 'CommentSyntax names grammars LanguageMap cannot produce: '.\implode(', ', $unreachable));
    }

    /**
     * A block entry is a PAIR.
     *
     * The nested shape means half a pair cannot be written as a row any more —
     * `['<!--', null]` was the plausible typo when the table had three flat
     * columns, and it would have downgraded a language to "no block form" in
     * silence. What is still writable is a one- or three-element list, which
     * the destructuring would take without complaint.
     */
    public function testEveryBlockEntryIsExactlyTwoDelimiters(): void
    {
        $malformed = [];
        foreach (self::rawTable() as $grammar => [, $block]) {
            if (null === $block || 2 === \count($block)) {
                continue;
            }
            $malformed[] = $grammar;
        }

        $this->assertSame([], $malformed, 'not a delimiter pair: '.\implode(', ', $malformed));
    }

    public function testTheThreeShapesAndTheAbsentOne(): void
    {
        $php = CommentSyntax::forGrammar('php');
        $this->assertSame('//', $php?->line);
        $this->assertSame(['/*', '*/'], $php?->block);

        $bash = CommentSyntax::forGrammar('bash');
        $this->assertSame('#', $bash?->line);
        $this->assertNull($bash?->block);

        $twig = CommentSyntax::forGrammar('twig');
        $this->assertNull($twig?->line);
        $this->assertSame(['{#', '#}'], $twig?->block);

        // Decided, not forgotten: JSON forbids comments (jsonc is the answer to
        // wanting them) and diff is a generated format.
        $this->assertNull(CommentSyntax::forGrammar('json'));
        $this->assertNull(CommentSyntax::forGrammar('diff'));

        // A grammar nothing claims, and no grammar at all.
        $this->assertNull(CommentSyntax::forGrammar('brainfuck'));
        $this->assertNull(CommentSyntax::forGrammar(null));
    }

    /**
     * CSS is the one entry that CHANGES an answer rather than adding one, so
     * it gets its own test: `//` is a SCSS extension, and in plain CSS it does
     * not comment the line out — it makes the parser discard the rule that
     * follows. Porta emitted it for the whole life of the feature.
     */
    public function testCssCommentsWithABlockAndScssStillDoesNot(): void
    {
        $this->assertNull(CommentSyntax::forGrammar('css')?->line);
        $this->assertSame(['/*', '*/'], CommentSyntax::forGrammar('css')?->block);

        $this->assertSame('//', CommentSyntax::forGrammar('scss')?->line);
    }

    /**
     * `forGrammar()` reproduces every row of the table it reads.
     *
     * This is the pair to the structural gates above, and it stays separate
     * from them: they read the RAW constant, so a lookup that dropped or
     * reordered a column would leave all three of them green.
     */
    public function testEveryRowSurvivesTheLookup(): void
    {
        $wrong = [];
        foreach (self::rawTable() as $grammar => $row) {
            $syntax = CommentSyntax::forGrammar($grammar);
            if ([$syntax?->line, $syntax?->block] === $row) {
                continue;
            }
            $wrong[] = $grammar;
        }

        $this->assertSame([], $wrong, 'forGrammar() disagrees with the table for: '.\implode(', ', $wrong));
    }

    /**
     * The declared table, by reflection.
     *
     * It is private because nothing in `src` needs to enumerate languages —
     * the editor asks about ONE grammar. Exposing it for a test would be
     * test-only code in src; the structural gates are the one question asked
     * from outside, and they need the raw rows rather than the lookup's
     * normalised answers.
     *
     * @return array<string, array{0: string|null, 1: array{string, string}|null}>
     */
    private static function rawTable(): array
    {
        // @mago-expect lint:inline-variable-return -- getConstant() returns
        // mixed, and the @var on the assignment is what gives the rows a shape;
        // inlined, there is nowhere to hang it.
        /** @var array<string, array{0: string|null, 1: array{string, string}|null}> $constant */
        $constant = Probe::getStatic(CommentSyntax::class, 'SYNTAX');

        return $constant;
    }
}
