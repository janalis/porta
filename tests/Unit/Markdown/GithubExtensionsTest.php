<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\TestCase;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\AlertKind;
use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\Emoji;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\FootnoteReference;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\ListItem;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\Text;
use Porta\Markdown\SourceRange;

/**
 * What GitHub renders that neither spec has examples for: task items, alerts,
 * footnotes and emoji shortcodes — and the source ranges a click or a later
 * edit needs from each.
 */
final class GithubExtensionsTest extends TestCase
{
    public function testATaskItemKnowsItsStateAndWhereItsBoxIs(): void
    {
        $markdown = "- [ ] open\n- [x] done\n- [X] shouted\n- plain\n";
        $list = new MarkdownParser()->parse($markdown)->blocks[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame([false, true, true, null], \array_map(static fn (ListItem $item): ?bool => $item->checked, $list->items));
        $box = $list->items[1]->checkbox;
        $this->assertInstanceOf(SourceRange::class, $box);
        $this->assertSame('[x]', \substr($markdown, $box->start, $box->length()));
        $paragraph = $list->items[0]->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $text = $paragraph->inlines[0];
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('open', $text->literal);
    }

    public function testABoxNeedsSpaceAfterItAndAnItemOfItsOwnIsAnEmptyTask(): void
    {
        $list = new MarkdownParser()->parse("- [x]done\n- [ ]\n")->blocks[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertNull($list->items[0]->checked, 'no space after the box: plain text');
        $this->assertFalse($list->items[1]->checked);
        $this->assertSame([], $list->items[1]->blocks);
    }

    public function testAQuoteOpeningWithAnAlertMarkerIsThatAlert(): void
    {
        $document = new MarkdownParser()->parse("> [!warning]\n> Mind the gap.\n\n> [!NOTE]\n\n> [!NOPE]\n> x\n");
        [$warning, $note, $quote] = $document->blocks;
        $this->assertInstanceOf(BlockQuote::class, $warning);
        $this->assertSame(AlertKind::Warning, $warning->alert);
        $paragraph = $warning->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertInstanceOf(Text::class, $paragraph->inlines[0]);
        $this->assertSame('Mind the gap.', $paragraph->inlines[0]->literal);
        $this->assertInstanceOf(BlockQuote::class, $note);
        $this->assertSame(AlertKind::Note, $note->alert);
        $this->assertSame([], $note->blocks, 'the marker line alone leaves no paragraph');
        $this->assertInstanceOf(BlockQuote::class, $quote);
        $this->assertNull($quote->alert);
    }

    public function testAFootnoteReferenceNeedsItsDefinition(): void
    {
        $markdown = "Body[^1] and[^missing].\n\n[^1]: The note\n    goes on.\n";
        $document = new MarkdownParser()->parse($markdown);
        $paragraph = $document->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $reference = $paragraph->inlines[1];
        $this->assertInstanceOf(FootnoteReference::class, $reference);
        $this->assertSame('1', $reference->label);
        $this->assertSame('[^1]', \substr($markdown, $reference->range->start, $reference->range->length()));
        $this->assertInstanceOf(Text::class, $paragraph->inlines[2]);
        $this->assertSame(' and[^missing].', $paragraph->inlines[2]->literal);
        $definition = $document->blocks[1];
        $this->assertInstanceOf(FootnoteDefinition::class, $definition);
        $this->assertSame('1', $definition->label);
        $note = $definition->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $note);
        $this->assertCount(3, $note->inlines, 'the indented line continues the note');
    }

    public function testAShortcodeIsAnEmojiOnlyWhenGithubKnowsIt(): void
    {
        $markdown = "Ship it :rocket: :not_an_emoji: 12:30:45\n";
        $paragraph = new MarkdownParser()->parse($markdown)->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $emoji = $paragraph->inlines[1];
        $this->assertInstanceOf(Emoji::class, $emoji);
        $this->assertSame('rocket', $emoji->shortcode);
        $this->assertSame("\u{1F680}", $emoji->glyph);
        $this->assertSame(':rocket:', \substr($markdown, $emoji->range->start, $emoji->range->length()));
        $this->assertInstanceOf(Text::class, $paragraph->inlines[2]);
        $this->assertSame(' :not_an_emoji: 12:30:45', $paragraph->inlines[2]->literal);
    }

    public function testAnExtendedAutolinkLeavesTheSentencesPunctuationOut(): void
    {
        $paragraph = new MarkdownParser()->parse("See www.example.com/a_(b)). Or (https://x.org/y?q=1)!\n")->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $links = \array_values(\array_filter($paragraph->inlines, static fn (Inline $node): bool => $node instanceof Link));
        $this->assertSame(['http://www.example.com/a_(b)', 'https://x.org/y?q=1'], \array_map(static fn (Link $link): string => $link->destination, $links));
    }

    public function testALinkInsideALinksTextStaysText(): void
    {
        $paragraph = new MarkdownParser()->parse("[see www.example.com](/here)\n")->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $link = $paragraph->inlines[0];
        $this->assertInstanceOf(Link::class, $link);
        $this->assertCount(1, $link->inlines);
        $this->assertInstanceOf(Text::class, $link->inlines[0]);
        $this->assertSame('see www.example.com', $link->inlines[0]->literal);
    }

    public function testRangesPointIntoNestedContainersAndThroughTabs(): void
    {
        $markdown = "> - a *b*\n>   c\n\n-\tx\n";
        $document = new MarkdownParser()->parse($markdown);
        $quote = $document->blocks[0];
        $this->assertInstanceOf(BlockQuote::class, $quote);
        $list = $quote->blocks[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $paragraph = $list->items[0]->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $slices = \array_map(static fn (Inline $node): string => \substr($markdown, $node->range->start, $node->range->length()), $paragraph->inlines);
        $this->assertSame(['a ', '*b*', "\n", 'c'], $slices);
        $this->assertEquals(new SourceRange(2, 3), $list->items[0]->marker);
        $tabbed = $document->blocks[1];
        $this->assertInstanceOf(ListBlock::class, $tabbed);
        $item = $tabbed->items[0]->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $item);
        $this->assertSame('x', \substr($markdown, $item->range->start, $item->range->length()));
    }
}
