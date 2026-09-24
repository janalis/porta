<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Markup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\Markup\Markup;
use Porta\Ui\Markup\MarkupNode;
use Porta\Ui\Markup\MarkupParser;
use Porta\Ui\Role;

final class MarkupParserTest extends TestCase
{
    public function testTextWithoutTagsIsOneTextChild(): void
    {
        $node = MarkupParser::parse('plain words');

        $this->assertNull($node->spec);
        $this->assertSame(['plain words'], $node->children);
    }

    public function testAnOpenedTagHoldsItsTextUntilTheBareClose(): void
    {
        $node = MarkupParser::parse('a <info>b</> c');

        $this->assertSame('a ', $node->children[0]);
        $span = $node->children[1];
        $this->assertInstanceOf(MarkupNode::class, $span);
        $this->assertSame(Role::Added, $span->spec?->role);
        $this->assertSame(['b'], $span->children);
        $this->assertSame(' c', $node->children[2]);
    }

    public function testANamedCloseClosesEverySpanInsideIt(): void
    {
        $node = MarkupParser::parse('<info>a<comment>b</info>c');

        $outer = $node->children[0];
        $this->assertInstanceOf(MarkupNode::class, $outer);
        $this->assertSame('a', $outer->children[0]);
        $inner = $outer->children[1];
        $this->assertInstanceOf(MarkupNode::class, $inner);
        $this->assertSame(Role::StatusWarning, $inner->spec?->role);
        $this->assertSame('c', $node->children[1], 'text after </info> sits outside both spans');
    }

    public function testAnUnclosedTagRunsToTheEnd(): void
    {
        $node = MarkupParser::parse('a<error>b');

        $span = $node->children[1];
        $this->assertInstanceOf(MarkupNode::class, $span);
        $this->assertSame(['b'], $span->children);
    }

    /** @return iterable<string, array{string, string}> */
    public static function literalTags(): iterable
    {
        yield 'an unknown name' => ['a <nope>b</nope>', 'a <nope>b</nope>'];
        yield 'an unknown key' => ['<size=3>x', '<size=3>x'];
        yield 'an unknown color' => ['<fg=chartreuse>x</>', '<fg=chartreuse>x'];
        yield 'a palette index past 255' => ['<fg=256>x', '<fg=256>x'];
        yield 'a malformed hex' => ['<bg=#12>x', '<bg=#12>x'];
        yield 'an unknown option' => ['<options=wavy>x', '<options=wavy>x'];
        yield 'a close nothing opened' => ['a</info>b', 'a</info>b'];
        yield 'a stray bare close' => ['a</>b', 'ab'];
        yield 'an escaped tag' => ['\\<info>x', '<info>x'];
        yield 'an escaped close angle' => ['a \\> b', 'a > b'];
        yield 'not a tag at all' => ['1 < 2 > 0', '1 < 2 > 0'];
        yield 'a role name spelled with a doubled dash' => ['<diag--warn>x', '<diag--warn>x'];
        yield 'a role name in its case name' => ['<DiagWarn>x', '<DiagWarn>x'];
    }

    #[DataProvider('literalTags')]
    public function testWhatConsoleWouldNotStyleStaysText(string $markup, string $plain): void
    {
        $this->assertSame($plain, MarkupParser::strip($markup));
    }

    public function testEscapeRoundTripsAnyText(): void
    {
        foreach (['<info>x</>', 'a\\', 'x\\<y', '</>', 'C:\\dir\\'] as $text) {
            $this->assertSame($text, MarkupParser::strip(MarkupParser::escape($text)), $text);
            $this->assertSame($text, Markup::literal($text)->plain(), $text);
        }
    }

    public function testFromReadsAStringAsLiteralAndAMarkupAsItself(): void
    {
        $markup = Markup::of('<info>ok</>');

        $this->assertSame($markup, Markup::from($markup));
        $this->assertSame('ok', $markup->plain());
        $this->assertSame('<info>ok</>', Markup::from('<info>ok</>')->plain());
    }

    public function testConsoleNamesAreNoRoleTag(): void
    {
        $tags = \array_map(static fn (Role $role): string => $role->tag(), Role::cases());

        foreach (['info', 'comment', 'question', 'error'] as $console) {
            $this->assertNotContains($console, $tags, "a role named {$console} would be shadowed by Console's style");
        }
    }
}
