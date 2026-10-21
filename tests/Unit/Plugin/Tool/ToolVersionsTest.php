<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\ToolVersions;
use Porta\Plugin\Tool\VersionText;

/**
 * What each installed tool says it is, asked of the binary once per path.
 *
 * Every test drives a scripted runner, so nothing here spawns a process.
 */
final class ToolVersionsTest extends TestCase
{
    /** @var list<array{0: list<string>, 1: string}> argv and cwd of every spawn */
    private array $ran = [];

    /** @var list<\Closure(int, string): void> exits held back, to answer later */
    private array $held = [];

    /**
     * A runner that answers each call from $answers, in order.
     *
     * @param list<array{0: int, 1: string}> $answers exit code and stdout, per call
     *
     * @return \Closure(list<string>, string, \Closure(int, string): void): void
     */
    private function runner(array $answers): \Closure
    {
        return function (array $argv, string $cwd, \Closure $onExit) use (&$answers): void {
            $this->ran[] = [$argv, $cwd];
            [$code, $out] = \array_shift($answers) ?? [-1, ''];
            $onExit($code, $out);
        };
    }

    /** @return \Closure(list<string>, string, \Closure(int, string): void): void a runner that keeps every exit to answer later */
    private function holdingRunner(): \Closure
    {
        return function (array $argv, string $cwd, \Closure $onExit): void {
            $this->ran[] = [$argv, $cwd];
            $this->held[] = $onExit;
        };
    }

    public function testProbeAsksTheBinaryAndCachesTheAnswer(): void
    {
        $versions = new ToolVersions($this->runner([[0, "dot - graphviz version 16.1.0 (20260904.0139)\n"]]));
        $this->assertSame('', $versions->known('/opt/bin/dot'), 'nothing is known before anything is asked');
        $this->assertFalse($versions->probed('/opt/bin/dot'));

        $then = 0;
        $versions->probe('/opt/bin/dot', ['-V'], static function () use (&$then): void {
            ++$then;
        });

        $this->assertSame('16.1.0', $versions->known('/opt/bin/dot'));
        $this->assertTrue($versions->probed('/opt/bin/dot'));
        $this->assertSame([['/opt/bin/dot', '-V'], '/opt/bin'], $this->ran[0], 'its own args, from its own directory');
        $this->assertSame(1, $then);
    }

    /** known() is read once per render; a probe per render would spawn a process per frame. */
    public function testAPathThatAnsweredIsNotAskedAgain(): void
    {
        $versions = new ToolVersions($this->runner([[0, 'custos 0.1.6']]));
        $versions->probe('/bin/custos');
        $then = 0;
        $versions->probe('/bin/custos', then: static function () use (&$then): void {
            ++$then;
        });

        $this->assertCount(1, $this->ran);
        $this->assertSame(1, $then, 'a cached answer still calls back');
    }

    public function testEachPathIsItsOwnQuestion(): void
    {
        $versions = new ToolVersions($this->runner([[0, 'custos 0.1.6'], [0, 'custos 0.1.7']]));
        $versions->probe('/bin/custos');
        $versions->probe('/home/me/.porta/bin/custos');

        $this->assertSame('0.1.6', $versions->known('/bin/custos'));
        $this->assertSame('0.1.7', $versions->known('/home/me/.porta/bin/custos'));
    }

    public function testConcurrentAsksShareOneProcess(): void
    {
        $versions = new ToolVersions($this->holdingRunner());
        $heard = [];
        $versions->probe('/bin/x', then: static function () use (&$heard): void {
            $heard[] = 'first';
        });
        $versions->probe('/bin/x');
        $versions->probe('/bin/x', then: static function () use (&$heard): void {
            $heard[] = 'second';
        });

        $this->assertCount(1, $this->ran, 'one process for one question');
        $this->held[0](0, reported: 'x 1.2.3');
        $this->assertSame(['first', 'second'], $heard, 'everyone waiting hears it');
        $this->assertSame('1.2.3', $versions->known('/bin/x'));
    }

    public function testABinaryThatFailsOrSaysNothingUsableIsUnknown(): void
    {
        $versions = new ToolVersions($this->runner([[1, 'x 1.2.3'], [0, '?-?-?']]));
        $versions->probe('/bin/broken');
        $versions->probe('chroma');

        $this->assertSame('', $versions->known('/bin/broken'), 'a failed run names no version, even one it printed');
        $this->assertTrue($versions->probed('/bin/broken'), 'but it answered, and is not asked again');
        $this->assertSame('', $versions->known('chroma'));
        $this->assertSame(\sys_get_temp_dir(), $this->ran[1][1], 'a bare name runs from the temp dir');
    }

    public function testRememberAndForget(): void
    {
        $versions = new ToolVersions($this->runner([[0, 'x 2.0.0']]));
        $versions->remember('/bin/x', '1.0.0');
        $versions->probe('/bin/x');
        $this->assertSame('1.0.0', $versions->known('/bin/x'), 'what an install reported needs no probe');
        $this->assertSame([], $this->ran);

        $versions->forget('/bin/x');
        $this->assertFalse($versions->probed('/bin/x'));
        $versions->probe('/bin/x');
        $this->assertSame('2.0.0', $versions->known('/bin/x'));
    }

    public function testVersionTextFindsTheFirstTriple(): void
    {
        $this->assertSame('0.21.0', VersionText::of('Symfony Language Tools 0.21.0'));
        $this->assertSame('2.1.17-dev', VersionText::of("PHPStan - PHP Static Analysis Tool 2.1.17-dev\n"));
        $this->assertNull(VersionText::of('version 2.1'));
    }
}
