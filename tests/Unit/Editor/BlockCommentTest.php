<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CommentSyntax;
use Porta\Editor\Document;
use Porta\Editor\LineOperations;
use Porta\Editor\SelectionMode;

/**
 * The two comment verbs, which differ only in which form they PREFER.
 *
 * `toggle_comment` takes the line form and falls back to wrapping each line in
 * the block pair; `toggle_block_comment` takes the block pair and falls back to
 * the line form. That symmetry is the design: neither key is ever dead in a
 * language that can be commented at all, and the fallbacks are what tests here
 * are mostly about, because they are the halves that did not exist before.
 */
final class BlockCommentTest extends TestCase
{
    // --- the LINE verb falling back to a block ------------------------------

    public function testTheLineVerbWrapsEachLineWhenTheLanguageHasNoLineForm(): void
    {
        $doc = new Document("{{ a }}\n{{ b }}\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        // Not column 0: a selection ending there deliberately excludes the line.
        $doc->setCursor(1, 7, SelectionMode::Keep);
        $doc->toggleComment(CommentSyntax::blockOnly('{#', '#}'));

        $this->assertSame(['{# {{ a }} #}', '{# {{ b }} #}', ''], $doc->lines);
    }

    public function testAndUnwrapsThemAgain(): void
    {
        $doc = new Document("{# {{ a }} #}\n{# {{ b }} #}\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 13, SelectionMode::Keep);
        $doc->toggleComment(CommentSyntax::blockOnly('{#', '#}'));

        $this->assertSame(['{{ a }}', '{{ b }}', ''], $doc->lines);
    }

    /**
     * Indentation stays outside the delimiters, exactly as the line form puts
     * the prefix after it. A block comment that swallowed the leading
     * whitespace would be legal and would flatten the file.
     */
    public function testTheIndentStaysOutsideTheDelimiters(): void
    {
        $doc = new Document("    a { b: c }\n");
        $doc->setCursor(0, 0);
        $doc->toggleComment(CommentSyntax::blockOnly('/*', '*/'));

        $this->assertSame('    /* a { b: c } */', $doc->getLine(0));
    }

    /**
     * Uncommenting an INDENTED line puts the indent back in front.
     *
     * Three separate mutants lived here — dropping the `trim()` that finds the
     * comment, dropping the indent from the result, and swapping the two — and
     * all three survived every test above, because a block that is UNCOMMENTED
     * never reaches the branch that rebuilds a line from its indent.
     */
    public function testUncommentingRestoresTheIndentAheadOfTheContent(): void
    {
        $doc = new Document("    /* a { b: c } */\n");
        $doc->setCursor(0, 0);
        $doc->toggleComment(CommentSyntax::blockOnly('/*', '*/'));

        $this->assertSame('    a { b: c }', $doc->getLine(0));
    }

    /**
     * One uncommented line in the middle means the whole block gets commented,
     * which is the same rule the line form has always followed. Asserting it
     * for the block form matters because the two implementations are separate
     * loops and only one of them was ever tested.
     */
    public function testAPartlyCommentedBlockCommentsRatherThanUncomments(): void
    {
        $doc = new Document("{# a #}\nb\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 1, SelectionMode::Keep);
        $doc->toggleComment(CommentSyntax::blockOnly('{#', '#}'));

        $this->assertSame(['{# {# a #} #}', '{# b #}', ''], $doc->lines);
    }

    public function testBlankLinesAreLeftAloneJustAsTheyAreForTheLineForm(): void
    {
        $doc = new Document("a\n\nb\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 1, SelectionMode::Keep);
        $doc->toggleComment(CommentSyntax::blockOnly('<!--', '-->'));

        $this->assertSame(['<!-- a -->', '', '<!-- b -->', ''], $doc->lines);
    }

    /**
     * `<!-->` opens with `<!--` and closes with `-->` and is neither: five
     * characters cannot hold seven characters of delimiter. Without the length
     * clause the unwrap computes a negative inner length, and `substr` with a
     * negative length counts back from the END of the string — so this does not
     * throw, it silently produces garbage.
     */
    public function testADelimiterPairThatOverlapsIsNotAComment(): void
    {
        $this->assertNull(LineOperations::blockUnwrapped('<!-->', '<!--', '-->'));

        $doc = new Document("<!-->\n");
        $doc->setCursor(0, 0);
        $doc->toggleComment(CommentSyntax::blockOnly('<!--', '-->'));

        $this->assertSame('<!-- <!--> -->', $doc->getLine(0));
    }

    /**
     * The two edges of "is this text a block comment".
     *
     * `/*` + `*\/` is FOUR characters and `/**\/` is four characters long, so
     * the length test has to be `<` and not `<=` — an empty comment is still a
     * comment, and off by one it stops being removable. And half a comment
     * (`/* foo`, unterminated) must not unwrap: with the two `str_` tests
     * joined by `&&` instead of `||`, a text that opens without closing walks
     * straight into the substr.
     */
    public function testAnEmptyCommentUnwrapsAndAnUnterminatedOneDoesNot(): void
    {
        $this->assertSame('', LineOperations::blockUnwrapped('/**/', '/*', '*/'), 'an empty comment is one');
        $this->assertNull(LineOperations::blockUnwrapped('/* foo', '/*', '*/'), 'opened and never closed');
        $this->assertNull(LineOperations::blockUnwrapped('foo */', '/*', '*/'), 'closed and never opened');
    }

    // --- the BLOCK verb -----------------------------------------------------

    /**
     * Character-precise, which is the whole difference from the line verb: the
     * delimiters land at the selection's edges, not around whole lines.
     */
    public function testTheBlockVerbWrapsExactlyTheSelection(): void
    {
        $doc = new Document("foo(bar, baz)\n");
        $doc->setCursor(0, 4);
        $doc->beginSelect();
        $doc->setCursor(0, 7, SelectionMode::Keep);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('foo(/* bar */, baz)', $doc->getLine(0));
    }

    public function testWithNoSelectionItTakesTheLinesContentAndNotItsIndent(): void
    {
        $doc = new Document("    return 1;\n");
        $doc->setCursor(0, 8);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('    /* return 1; */', $doc->getLine(0));
        $this->assertSame(19, $doc->cursorCol, 'the caret follows the insert; only an EMPTY comment pulls it back inside');
    }

    /**
     * Trailing whitespace stays OUTSIDE the comment, the same way the indent
     * does. Without the `rtrim()` the closer lands after it, which is
     * invisible on screen and shows up as trailing space in the diff.
     */
    public function testTrailingWhitespaceIsNotSweptIntoTheComment(): void
    {
        $doc = new Document("    return 1;   \n");
        $doc->setCursor(0, 8);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('    /* return 1; */   ', $doc->getLine(0));
    }

    /**
     * A blank line that is INDENTED. `rtrim()` takes it to length zero while
     * the content span starts at its full width, so without a clamp the range
     * runs backwards — and a backwards range does not throw, it reads as text
     * through `substr`'s negative length and deletes the indent on its way
     * past. Found by a mutant on the caret arithmetic, which was equivalent at
     * column zero and is not here.
     */
    public function testAnIndentedBlankLineKeepsItsIndent(): void
    {
        $doc = new Document("    \n");
        $doc->setCursor(0, 4);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('    /*  */', $doc->getLine(0));
        $this->assertSame(7, $doc->cursorCol, 'the caret sits between the delimiters, not at a fixed column');
    }

    /**
     * The un-toggle. With no selection the target is the whole line, so a
     * one-line block comment comes off from anywhere on it — which is the case
     * a user actually hits, and the reason the no-selection target is the
     * content span rather than the word at the caret.
     */
    public function testItComesOffAgainFromAnywhereOnTheLine(): void
    {
        $doc = new Document("    /* return 1; */\n");
        $doc->setCursor(0, 12);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('    return 1;', $doc->getLine(0));
    }

    public function testItSpansSeveralLinesWithOnePair(): void
    {
        $doc = new Document("a\nb\nc\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 1, SelectionMode::Keep);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame(['/* a', 'b', 'c */', ''], $doc->lines);
    }

    /**
     * On a blank line there is nothing to comment OUT, so the verb means
     * "open a comment here" — and the caret has to land between the
     * delimiters, or the user's next keystroke goes after the closer and the
     * comment they just opened is empty forever.
     */
    public function testOnABlankLineItOpensAnEmptyCommentAndPutsTheCaretInside(): void
    {
        $doc = new Document("\n");
        $doc->setCursor(0, 0);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('/*  */', $doc->getLine(0));
        $this->assertSame(3, $doc->cursorCol);

        $doc->insert('why');
        $this->assertSame('/* why */', $doc->getLine(0));
    }

    /**
     * Emptying a comment must not leave the selection behind. `insert('')`
     * returns before it clears one, so the delete is explicit — without it the
     * `/*` and `*\/` stay on screen.
     */
    public function testEmptyingACommentRemovesItEntirely(): void
    {
        $doc = new Document("/*  */\n");
        $doc->setCursor(0, 0);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));

        $this->assertSame('', $doc->getLine(0));
    }

    /**
     * A language with no block form is not left with a dead key: the block
     * verb does what the line verb would.
     */
    public function testTheBlockVerbFallsBackToTheLineFormWhenThereIsNoPair(): void
    {
        $doc = new Document("print(1)\n");
        $doc->setCursor(0, 0);
        $doc->toggleBlockComment(CommentSyntax::lineOnly('#'));

        $this->assertSame('# print(1)', $doc->getLine(0));
    }

    /**
     * One keypress, one undo. The block verb makes two edits (delete the range,
     * insert the replacement) and they have to collapse, or Ctrl+Z leaves the
     * line half-commented.
     */
    public function testOneKeypressIsOneUndoStep(): void
    {
        $doc = new Document("foo(bar, baz)\n");
        $doc->setCursor(0, 4);
        $doc->beginSelect();
        $doc->setCursor(0, 7, SelectionMode::Keep);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));
        $this->assertSame('foo(/* bar */, baz)', $doc->getLine(0));

        $this->assertTrue($doc->undo());
        $this->assertSame('foo(bar, baz)', $doc->getLine(0));
    }

    /**
     * And the compound CLOSES again. An unbalanced `beginCompound()` leaves the
     * document folding every later edit into the same step for the rest of the
     * session, which no assertion about this keypress alone can see — the undo
     * above passes either way. What it costs is the NEXT undo.
     */
    public function testTheNextEditIsItsOwnUndoStep(): void
    {
        $doc = new Document("foo\n");
        $doc->setCursor(0, 0);
        $doc->toggleBlockComment(CommentSyntax::both('//', '/*', '*/'));
        $this->assertSame('/* foo */', $doc->getLine(0));

        $doc->setCursor(0, 9);
        $doc->insert('X');
        $this->assertTrue($doc->undo());

        $this->assertSame('/* foo */', $doc->getLine(0), 'undo took back the typing, not the comment with it');
    }
}
