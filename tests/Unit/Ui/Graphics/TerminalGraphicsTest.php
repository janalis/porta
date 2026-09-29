<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Graphics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Graphics\TerminalGraphics;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TerminalGraphicsTest extends TestCase
{
    private const string QUERY = "\x1b_Gi=31,s=1,v=1,a=q,t=d,f=24;AAAA\x1b\\\x1b[16t\x1b[c";

    private VirtualTerminal $terminal;
    private FakeScheduler $scheduler;
    private TerminalGraphics $graphics;
    private int $renders = 0;
    private int $changes = 0;

    protected function setUp(): void
    {
        $this->terminal = new VirtualTerminal(columns: 80, rows: 24);
        $this->scheduler = new FakeScheduler();
        $renders = &$this->renders;
        // Counted, because a render request is half of what an answer owes the
        // panes (the other half, the invalidation, is the listener below).
        $tui = new class($this->terminal, $renders) extends Tui {
            /** @var int */
            private $renders;

            public function __construct(VirtualTerminal $terminal, int &$renders)
            {
                parent::__construct(terminal: $terminal, keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
                $this->renders = &$renders;
            }

            #[\Override]
            public function requestRender(bool $force = false): void
            {
                ++$this->renders;
            }
        };
        $this->graphics = new TerminalGraphics($tui, $this->scheduler);
        $this->graphics->onChange(function (): void {
            ++$this->changes;
        });
    }

    /** Feed one reply; true when the listener consumed it. */
    private function reply(string $data): bool
    {
        $event = new InputEvent($data);
        $this->graphics->onInput($event);

        return $event->isPropagationStopped();
    }

    public function testTheProbeAsksForGraphicsTheCellSizeAndThenDeviceAttributes(): void
    {
        $this->graphics->probe([]);

        $this->assertSame(self::QUERY, $this->terminal->getOutput());
        $this->assertFalse($this->graphics->available(), 'nothing is assumed before the answer');
        $this->assertSame([1.5], \array_values($this->scheduler->delays));
    }

    public function testAnOkBeforeTheDeviceAttributesTurnsPixelsOnAndTellsThePanes(): void
    {
        $this->graphics->probe([]);

        $this->assertTrue($this->reply("\x1b_Gi=31;OK\x1b\\"));
        $this->assertFalse($this->graphics->available(), 'the answer is not final until DA1');
        $this->assertTrue($this->reply("\x1b[?62;22;52c"));

        $this->assertTrue($this->graphics->available());
        $this->assertSame(1, $this->changes);
        $this->assertSame(1, $this->renders);
        $this->assertSame(['1'], $this->scheduler->cancelled, 'the timeout is disarmed');
    }

    public function testDeviceAttributesWithNoOkMeanNoGraphics(): void
    {
        $this->graphics->probe([]);
        $this->assertTrue($this->reply("\x1b_Gi=31;ENOTSUPPORTED:nope\x1b\\"));
        $this->reply("\x1b[?62;22c");

        $this->assertFalse($this->graphics->available());
        $this->assertSame(1, $this->changes, 'still an answer: the panes stop waiting');
    }

    public function testASilentTerminalTimesOutToNo(): void
    {
        $this->graphics->probe([]);
        $this->scheduler->fire();

        $this->assertFalse($this->graphics->available());
        $this->assertSame(1, $this->changes);
    }

    public function testAnOkWhoseDeviceAttributesNeverCameStillCountsAtTheTimeout(): void
    {
        $this->graphics->probe([]);
        $this->reply("\x1b_Gi=31;OK\x1b\\");
        $this->scheduler->fire();

        $this->assertTrue($this->graphics->available());
    }

    public function testALateDeviceAttributesReplyDecidesNothingTwice(): void
    {
        $this->graphics->probe([]);
        $this->scheduler->fire();
        $this->reply("\x1b_Gi=31;OK\x1b\\");

        $this->assertTrue($this->reply("\x1b[?62;22c"), 'still swallowed');
        $this->assertFalse($this->graphics->available(), 'the timeout already answered');
        $this->assertSame(1, $this->changes);
    }

    public function testTheCellSizeReplyIsReadAndSwallowed(): void
    {
        $this->assertSame([8, 16], $this->graphics->cellPixels(), 'the default before any answer');
        $this->assertTrue($this->reply("\x1b[6;34;16t"));

        $this->assertSame([16, 34], $this->graphics->cellPixels());
    }

    public function testKeysPassThrough(): void
    {
        $this->graphics->probe([]);

        foreach (['a', "\x1b", "\x1b[A", "\x1b[c", "\x1b[6;34t", "\x1b[<0;1;1M"] as $key) {
            $this->assertFalse($this->reply($key), \addcslashes($key, characters: "\0..\37"));
        }
        $this->assertFalse($this->graphics->available());
    }

    public function testOffDecidesWithoutAsking(): void
    {
        $this->graphics->probe(['PORTA_GRAPHICS' => 'off', 'TERM_PROGRAM' => 'ghostty']);

        $this->assertSame('', $this->terminal->getOutput());
        $this->assertSame([], $this->scheduler->scheduled);
        $this->assertFalse($this->graphics->available());
        $this->assertSame(1, $this->changes, 'the panes are told there will be no answer');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function incapableTerminals(): iterable
    {
        yield 'WezTerm' => [['TERM_PROGRAM' => 'WezTerm']];
        yield 'Konsole' => [['KONSOLE_VERSION' => '230800']];
        yield 'tmux' => [['TMUX' => '/tmp/tmux-501/default,1,0']];
    }

    /** @param array<string, string> $env */
    #[DataProvider('incapableTerminals')]
    public function testATerminalThatAnswersButCannotDrawAPlaceholderIsNotAsked(array $env): void
    {
        $this->graphics->probe($env);

        $this->assertSame('', $this->terminal->getOutput());
        $this->assertFalse($this->graphics->available());
    }

    public function testKittyForcesThePixelsOnAndStillAsksForTheCellSize(): void
    {
        $this->graphics->probe(['PORTA_GRAPHICS' => 'kitty', 'TMUX' => '/tmp/tmux']);

        $this->assertTrue($this->graphics->available());
        $this->assertSame(self::QUERY, $this->terminal->getOutput());
        $this->reply("\x1b[?62;22c");
        $this->assertTrue($this->graphics->available(), 'a terminal that says nothing is overruled');
        $this->assertSame(0, $this->changes, 'forcing it is not a change a pane missed');
    }

    public function testATransmitIsChunkedWithTheKeysOnTheFirstChunkOnly(): void
    {
        $png = \str_repeat("\xAB", times: 4000); // 5336 base64 bytes: two chunks
        $this->graphics->transmit(1000, $png);

        $out = $this->terminal->getOutput();
        $this->assertSame(2, \preg_match_all('/\x1b_G([^;]*);([^\x1b]*)\x1b\\\\/', $out, $chunks));
        $this->assertSame(['a=t,f=100,t=d,i=1000,q=2,m=1', 'm=0'], $chunks[1]);
        $this->assertSame(4096, \strlen($chunks[2][0]));
        $this->assertSame(\base64_encode($png), $chunks[2][0].$chunks[2][1]);
    }

    public function testAnUnchangedPlacementIsNotResent(): void
    {
        $this->graphics->place(1000, 10, 5);
        $this->graphics->place(1000, 10, 5);

        $this->assertSame("\x1b_Ga=p,U=1,i=1000,p=1,c=10,r=5,q=2\x1b\\", $this->terminal->getOutput());

        $this->graphics->place(1000, 10, 6);
        $this->assertStringEndsWith("\x1b_Ga=p,U=1,i=1000,p=1,c=10,r=6,q=2\x1b\\", $this->terminal->getOutput());
    }

    public function testANewTransmitNeedsItsPlacementAgain(): void
    {
        // Replacing an id's pixels drops the placement with them.
        $this->graphics->place(1000, 1, 1);
        $this->graphics->transmit(1000, 'png');
        $this->terminal->clearOutput();
        $this->graphics->place(1000, 1, 1);

        $this->assertSame("\x1b_Ga=p,U=1,i=1000,p=1,c=1,r=1,q=2\x1b\\", $this->terminal->getOutput());
    }

    public function testOnlyTransmittedImagesAreDeletedAndOnlyOnce(): void
    {
        $this->graphics->delete(1000);
        $this->assertSame('', $this->terminal->getOutput(), 'the terminal holds nothing under that id');

        $this->graphics->transmit(1000, 'png');
        $this->terminal->clearOutput();
        $this->graphics->delete(1000);
        $this->graphics->delete(1000);

        $this->assertSame("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\", $this->terminal->getOutput());
    }

    public function testReleaseFreesEveryImageAndStopsWaiting(): void
    {
        $this->graphics->probe([]);
        $this->graphics->transmit($this->graphics->allocateId(), 'a');
        $this->graphics->transmit($this->graphics->allocateId(), 'b');
        $this->terminal->clearOutput();

        $this->graphics->release();

        $this->assertSame("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\\x1b_Ga=d,d=I,i=1001,q=2\x1b\\", $this->terminal->getOutput());
        $this->assertSame([], $this->scheduler->scheduled);
    }
}
