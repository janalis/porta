<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\DroppedPaths;
use Porta\Tests\Support\TempProject;

/**
 * What a terminal pastes for a drop, read back as the paths it names — and
 * every paste that is NOT one left alone.
 *
 * The fixture's directory is realpath'd once: on macOS the temp dir is
 * `/var/…` and its realpath `/private/var/…`, and the parser answers in
 * realpath space.
 */
final class DroppedPathsTest extends TestCase
{
    /** Held: a TempProject deletes its directory when it is freed. */
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject(['a b.php' => '', 'c.php' => '', "it's.php" => '', 'sub/d.php' => '', 'q".php' => '']);
    }

    public function testABackslashEscapedPathIsOneDrop(): void
    {
        $this->assertSame([$this->dir().'/a b.php'], DroppedPaths::parse($this->dir().'/a\ b.php'));
    }

    public function testSeveralPathsAreSeparatedBySpacesTabsOrLines(): void
    {
        $this->assertSame(
            [$this->dir().'/a b.php', $this->dir().'/c.php', $this->dir().'/sub', $this->dir().'/sub/d.php'],
            DroppedPaths::parse($this->dir()."/a\\ b.php  {$this->dir()}/c.php\t{$this->dir()}/sub\r\n{$this->dir()}/sub/d.php\n"),
        );
    }

    public function testSingleQuotesTakeEverythingLiterally(): void
    {
        $this->assertSame([$this->dir().'/a b.php'], DroppedPaths::parse("'{$this->dir()}/a b.php'"));
        $this->assertSame([$this->dir()."/it's.php"], DroppedPaths::parse("'{$this->dir()}/it'\\''s.php'"), 'the shell way to quote a quote');
    }

    public function testDoubleQuotesHonourBackslashEscapes(): void
    {
        $this->assertSame([$this->dir().'/a b.php'], DroppedPaths::parse("\"{$this->dir()}/a b.php\""));
        $this->assertSame([$this->dir().'/a b.php'], DroppedPaths::parse("\"{$this->dir()}/a\\ b.php\""));
        $this->assertSame([$this->dir().'/q".php'], DroppedPaths::parse("\"{$this->dir()}/q\\\".php\""), 'an escaped quote does not close them');
    }

    public function testAQuoteLeftOpenIsText(): void
    {
        $this->assertNull(DroppedPaths::parse("'{$this->dir()}/c.php"));
        $this->assertNull(DroppedPaths::parse("\"{$this->dir()}/c.php"));
        $this->assertNull(DroppedPaths::parse("\"{$this->dir()}/c.php\\\""), 'the escaped quote is not the closing one');
    }

    public function testAFileUrlIsDecoded(): void
    {
        $this->assertSame([$this->dir().'/a b.php'], DroppedPaths::parse('file://'.\str_replace(search: ' ', replace: '%20', subject: $this->dir().'/a b.php')));
        $this->assertSame([$this->dir().'/c.php'], DroppedPaths::parse('file://localhost'.$this->dir().'/c.php'), 'an authority is dropped');
        $this->assertNull(DroppedPaths::parse('file://host-only'), 'a URL with no path names nothing');
    }

    public function testAnythingThatIsNotEveryPathExistingIsText(): void
    {
        $this->assertNull(DroppedPaths::parse(''));
        $this->assertNull(DroppedPaths::parse("  \n"));
        $this->assertNull(DroppedPaths::parse('hello world'));
        $this->assertNull(DroppedPaths::parse($this->dir().'/c.php '.$this->dir().'/missing.php'), 'one missing path makes the whole paste text');
        $this->assertNull(DroppedPaths::parse('c.php'), 'a relative path is text, whatever the cwd holds');
    }

    public function testAPasteTooLongToBeADropIsTextWithoutLookingAtIt(): void
    {
        $this->assertNotNull(DroppedPaths::parse(\str_pad($this->dir().'/c.php', length: 65_536)), 'at the limit, still read');
        $this->assertNull(DroppedPaths::parse(\str_pad($this->dir().'/c.php', length: 65_537)));
    }

    public function testAPathIsKeptAsItWasSpelled(): void
    {
        $this->assertSame([$this->dir().'/sub/../c.php'], DroppedPaths::parse($this->dir().'/sub/../c.php'), 'not resolved: the caller asks for that where it matters');
    }

    public function testADanglingSymlinkIsStillSomethingToDrop(): void
    {
        \symlink($this->dir().'/gone', $this->dir().'/dangling');

        $this->assertSame([$this->dir().'/dangling'], DroppedPaths::parse($this->dir().'/dangling'));
    }

    public function testAUriListSkipsCommentsAndBlankLines(): void
    {
        $this->assertSame(
            [$this->dir().'/a b.php', $this->dir().'/c.php'],
            DroppedPaths::fromUriList("# from kitty\r\nfile://".\str_replace(search: ' ', replace: '%20', subject: $this->dir().'/a b.php')."\r\n\r\n  file://{$this->dir()}/c.php  \r\n"),
        );
        $this->assertNull(DroppedPaths::fromUriList("# only a comment\r\n"));
        $this->assertNull(DroppedPaths::fromUriList('file:///nowhere/at/all'));
    }

    private function dir(): string
    {
        return (string) \realpath($this->project->dir);
    }
}
