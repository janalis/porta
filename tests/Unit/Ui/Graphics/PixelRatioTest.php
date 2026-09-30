<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Graphics;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PumpsLoop;
use Porta\Ui\Graphics\PixelRatio;
use Porta\Ui\Graphics\TerminalGraphics;

/**
 * What 100% zoom means on this display: the setting's own ratio, or what the
 * system says the screen is.
 */
final class PixelRatioTest extends TestCase
{
    use PumpsLoop;

    private TerminalGraphics $graphics;

    /** @param list<string> $query the system query's stand-in */
    private function ratio(array $query = ['porta-no-such-binary-e2f7']): PixelRatio
    {
        [$this->graphics] = PixelGraphics::answered();

        return new PixelRatio($this->graphics, $query);
    }

    /** A stand-in for the macOS query that prints $out and exits with $exit. */
    private static function says(string $out, int $exit = 0): array
    {
        return [\PHP_BINARY, '-r', 'echo '.\var_export($out, return: true).'; exit('.$exit.');'];
    }

    public function testANamedRatioIsTheRatio(): void
    {
        $ratio = $this->ratio();
        $ratio->apply('1.5');
        $this->assertSame(1.0, $this->graphics->pixelRatio(), 'nothing before the probe');

        $ratio->probe([], os: 'Darwin');
        $this->assertSame(1.5, $this->graphics->pixelRatio(), 'named, even where the system could be asked');

        $ratio->apply('3');
        $this->assertSame(3.0, $this->graphics->pixelRatio(), 'a change applies live');
    }

    public function testOnLinuxAutoReadsTheToolkitsScale(): void
    {
        $ratio = $this->ratio();

        $ratio->probe(['GDK_SCALE' => '2'], os: 'Linux');
        $this->assertSame(2.0, $this->graphics->pixelRatio());

        $ratio->probe(['QT_SCALE_FACTOR' => '1.25'], os: 'Linux');
        $this->assertSame(1.25, $this->graphics->pixelRatio());

        $ratio->probe(['GDK_SCALE' => 'big'], os: 'Linux');
        $this->assertSame(1.0, $this->graphics->pixelRatio(), 'what is no number counts for nothing');
    }

    public function testOverSshTheScreenIsAnotherMachinesAndAutoIsOne(): void
    {
        $ratio = $this->ratio(self::says('2'));

        $ratio->probe(['SSH_CONNECTION' => '10.0.0.1 22 10.0.0.2 51000', 'GDK_SCALE' => '2'], os: 'Darwin');
        $this->pump(0.3);

        $this->assertSame(1.0, $this->graphics->pixelRatio());
    }

    public function testOnMacosAutoAsksTheSystemOffTheLoop(): void
    {
        $ratio = $this->ratio(self::says("2\n"));

        $ratio->probe([], os: 'Darwin');
        $this->assertSame(1.0, $this->graphics->pixelRatio(), 'not yet: the answer comes back through the loop');

        $this->await(fn (): bool => 2.0 === $this->graphics->pixelRatio());
        $this->addToAssertionCount(1);
    }

    public function testAutoAsksNothingUntilTheTerminalSaysItDrawsPixels(): void
    {
        [$graphics] = PixelGraphics::unanswered();
        $ratio = new PixelRatio($graphics, self::says('2'));

        $ratio->probe([], os: 'Darwin');
        $this->pump(0.3);
        $this->assertSame(1.0, $graphics->pixelRatio(), 'half-blocks have no ratio to ask for');

        PixelGraphics::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[6;34;16t", "\x1b[?62c");
        $this->await(static fn (): bool => 2.0 === $graphics->pixelRatio());
        $this->addToAssertionCount(1);
    }

    public function testAQueryThatFailsOrSaysNothingUsefulLeavesOne(): void
    {
        foreach ([self::says('2', exit: 1), self::says('Retina'), ['porta-no-such-binary-e2f7']] as $query) {
            $ratio = $this->ratio($query);
            $this->graphics->setPixelRatio(2.0); // what a failure must not keep
            $ratio->probe([], os: 'Darwin');
            $this->pump(0.5);

            $this->assertSame(1.0, $this->graphics->pixelRatio(), \implode(' ', $query));
        }
    }

    public function testASettingChosenWhileTheSystemIsAskedWinsOverItsLateAnswer(): void
    {
        $ratio = $this->ratio([\PHP_BINARY, '-r', 'usleep(200000); echo "2";']);
        $ratio->probe([], os: 'Darwin');

        $ratio->apply('1.25');
        $this->pump(0.5);

        $this->assertSame(1.25, $this->graphics->pixelRatio());
    }

    public function testReleaseStopsAQueryStillOut(): void
    {
        $ratio = $this->ratio([\PHP_BINARY, '-r', 'usleep(200000); echo "2";']);
        $ratio->probe([], os: 'Darwin');

        $ratio->release();
        $this->pump(0.5);

        $this->assertSame(1.0, $this->graphics->pixelRatio());
    }

    public function testThePanesHearOfAChangeAndOnlyOfAChange(): void
    {
        $ratio = $this->ratio();
        $heard = 0;
        $this->graphics->onChange(static function () use (&$heard): void {
            ++$heard;
        });
        $ratio->probe([], os: 'Linux');
        $this->assertSame(0, $heard, '1 is what it already was');

        $ratio->apply('2');
        $ratio->apply('2');
        $this->graphics->setPixelRatio(0.5);

        $this->assertSame(2, $heard, 'to 2, then down to the floor of 1');
        $this->assertSame(1.0, $this->graphics->pixelRatio(), 'a ratio below 1 is 1');
    }
}
