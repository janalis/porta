<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Console\SelfUpdate;
use Porta\Tests\Support\TempProject;

/**
 * The self-update against a scripted `curl` / `php`: no network, no
 * credentials — what is asserted is what it asks for and what it does with
 * the answers.
 */
final class SelfUpdateTest extends TestCase
{
    /** @var list<array{list<string>, ?string}> every command run, with its stdin */
    private array $ran = [];

    /** What the scripted release carries. */
    private string $pharBytes = 'NEW PHAR';

    private ?string $shaOverride = null;

    private int $newPharRuns = 0;

    private int $downloadFails = 0;

    private function runner(): \Closure
    {
        return function (array $command, ?string $stdin = null): array {
            $this->ran[] = [$command, $stdin];
            [$bin] = $command;
            if ('curl' === $bin) {
                return $this->curl($command);
            }

            // The new phar, run before it replaces anything.
            return [$this->newPharRuns, "porta v1.3.0\n"];
        };
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function curl(array $command): array
    {
        $url = (string) \end($command);

        return match (true) {
            \str_ends_with($url, '/releases/latest') => [0, (string) \json_encode(['tag_name' => 'v1.3.0'], \JSON_THROW_ON_ERROR)],
            \str_contains($url, '/releases/tags/') => [
                0,
                (string) \json_encode(
                    [
                        'assets' => [
                            ['name' => 'porta.phar', 'url' => 'https://api/asset/1'],
                            ['name' => 'porta.phar.sha256', 'url' => 'https://api/asset/2'],
                            ['name' => 'notes.txt', 'url' => 'https://api/asset/3'],
                            ['name' => 'porta.phar'], // malformed: no url — skipped, not fatal
                            'garbage',
                        ],
                    ],
                    \JSON_THROW_ON_ERROR,
                ),
            ],
            default => $this->download($command),
        };
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function download(array $command): array
    {
        $out = $command[\array_search('-o', $command, strict: true) + 1];
        if (!\str_starts_with($out, '/')) {
            return [23, '']; // a mutant's argv: never written into the working directory
        }
        \file_put_contents($out, \str_ends_with($out, '.sha256') ? ($this->shaOverride ?? \hash('sha256', $this->pharBytes))."  porta.phar\n" : $this->pharBytes);

        return [$this->downloadFails, ''];
    }

    /** A made-up token, built rather than written: it is a test value, not a credential. */
    private static function fake(string $name): string
    {
        return \sprintf('fake-%s-%d', $name, \strlen($name));
    }

    private function installed(): TempProject
    {
        $dir = new TempProject(['porta.phar' => 'OLD PHAR']);
        \chmod($dir->path('porta.phar'), permissions: 0o751);

        return $dir;
    }

    public function testWithoutATokenTheLatestReleaseIsAskedForAnonymously(): void
    {
        $this->assertSame('v1.3.0', new SelfUpdate($this->runner(), ['GH_TOKEN' => ''])->latest());

        [$command, $stdin] = $this->ran[0];
        $this->assertSame('https://api.github.com/repos/janalis/porta/releases/latest', \end($command));
        $this->assertSame('', $stdin, 'no Authorization header');
    }

    public function testATokenReachesTheApiOnStdinNeverInTheArguments(): void
    {
        $this->assertSame('v1.3.0', new SelfUpdate($this->runner(), ['GITHUB_TOKEN' => self::fake('secret')])->latest());

        $this->assertCount(1, $this->ran, 'a token that works is not followed by an anonymous retry');
        [$command, $stdin] = $this->ran[0];
        $this->assertSame('curl', $command[0]);
        $this->assertStringContainsString('--proto =https --proto-redir =https', \implode(' ', $command), 'never downgraded to plain http');
        $this->assertSame('header = "Authorization: Bearer '.self::fake('secret').'"'."\n", $stdin, 'one curl config line');
        $this->assertStringNotContainsString(self::fake('secret'), \implode(' ', $command), 'a token in argv is visible to every process list');
    }

    public function testATokenGithubRefusesIsDroppedForAnAnonymousRetry(): void
    {
        $runner = $this->runner();
        $sent = [];
        $refusing = static function (array $command, ?string $stdin = null) use ($runner, &$sent): array {
            $sent[] = $stdin;

            return '' === $stdin ? $runner($command, $stdin) : [22, ''];
        };

        $this->assertSame('v1.3.0', new SelfUpdate($refusing, ['GH_TOKEN' => self::fake('stale')])->latest());
        $this->assertSame(['header = "Authorization: Bearer '.self::fake('stale').'"'."\n", ''], $sent, 'with the token, then without');
    }

    public function testGhTokenWinsOverGithubToken(): void
    {
        new SelfUpdate($this->runner(), ['GH_TOKEN' => self::fake('gh'), 'GITHUB_TOKEN' => self::fake('github')])->latest();

        $this->assertStringContainsString('Bearer '.self::fake('gh'), (string) $this->ran[0][1]);
    }

    public function testAnApiThatDoesNotAnswerIsReported(): void
    {
        $runner = static fn (array $command, ?string $stdin = null): array => [127, ''];

        $this->expectExceptionMessageIsOrContains('could not reach the releases of janalis/porta — check the connection, and that a release exists.');
        new SelfUpdate($runner, ['GH_TOKEN' => self::fake('t')])->latest();
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function versions(): iterable
    {
        yield 'a newer tag' => ['v1.3.0', 'v1.2.9', true];
        yield 'the same tag' => ['v1.3.0', 'v1.3.0', false];
        yield 'an older tag' => ['v1.2.0', 'v1.3.0', false];
        yield 'commits after the same tag' => ['v1.3.0', 'v1.3.0-4-gabc1234', false];
        yield 'commits after an older tag' => ['v1.3.0', 'v1.2.0-4-gabc1234-dirty', true];
        yield 'a bare commit build' => ['v0.0.1', '1d2bb88b', true];
        yield 'the checkout constant' => ['v0.2.0', '0.1.0', true];
    }

    #[DataProvider('versions')]
    public function testANewerReleaseIsATagAboveTheOneThisWasBuiltFrom(string $latest, string $current, bool $newer): void
    {
        $this->assertSame($newer, SelfUpdate::isNewer($latest, $current));
    }

    public function testInstallChecksRunsAndSwapsThePharKeepingItsMode(): void
    {
        $dir = $this->installed();

        new SelfUpdate($this->runner(), [])->install('v1.3.0', $dir->path('porta.phar'), '/usr/bin/php');

        $this->assertSame('NEW PHAR', \file_get_contents($dir->path('porta.phar')));
        $this->assertSame(0o751, \fileperms($dir->path('porta.phar')) & 0o777);
        $this->assertFileDoesNotExist($dir->path('.porta.phar.new'), 'nothing staged is left behind');
        $this->assertSame('/usr/bin/php', \end($this->ran)[0][0], 'the new phar ran before it went anywhere');
    }

    public function testInstallDownloadsTheTwoAssetsOnly(): void
    {
        $dir = $this->installed();

        new SelfUpdate($this->runner(), [])->install('v1.3.0', $dir->path('porta.phar'), 'php');

        $this->assertSame('NEW PHAR', \file_get_contents($dir->path('porta.phar')));
        $downloads = \array_filter($this->ran, static fn (array $call): bool => \in_array('-o', $call[0], strict: true));
        $this->assertCount(2, $downloads, 'not notes.txt');
    }

    /** @return iterable<string, array{\Closure(self): void, string}> */
    public static function refusals(): iterable
    {
        yield 'a checksum that does not match' => [
            static function (self $test): void {
                $test->shaOverride = \str_repeat('0', times: 64);
            },
            'does not match its checksum',
        ];
        yield 'a phar that does not run' => [
            static function (self $test): void {
                $test->newPharRuns = 255;
            },
            'does not run here',
        ];
        yield 'a download that fails' => [
            static function (self $test): void {
                $test->downloadFails = 1;
            },
            'could not download porta.phar of v1.3.0',
        ];
    }

    /** @param \Closure(self): void $arrange */
    #[DataProvider('refusals')]
    public function testARefusedUpdateLeavesThePharAsItWas(\Closure $arrange, string $says): void
    {
        $arrange($this);
        $dir = $this->installed();

        try {
            new SelfUpdate($this->runner(), [])->install('v1.3.0', $dir->path('porta.phar'), 'php');
            $this->fail('the update went through');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($says, $e->getMessage());
        }
        $this->assertSame('OLD PHAR', \file_get_contents($dir->path('porta.phar')));
    }

    public function testACopyThatCannotBeMadeLeavesThePharAsItWas(): void
    {
        $dir = $this->installed();
        \mkdir($dir->path('.porta.phar.new')); // where the copy would go: taken

        try {
            new SelfUpdate($this->runner(), [])->install('v1.3.0', $dir->path('porta.phar'), 'php');
            $this->fail('the update went through');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be replaced (is the disk full?); nothing was changed.', $e->getMessage());
        }
        $this->assertSame('OLD PHAR', \file_get_contents($dir->path('porta.phar')));
    }

    public function testAPharItCannotReplaceIsRefusedUpFront(): void
    {
        $dir = $this->installed();
        \chmod($dir->path('porta.phar'), permissions: 0o444);

        try {
            $this->expectExceptionMessageIsOrContains('is not writable');
            new SelfUpdate($this->runner(), [])->install('v1.3.0', $dir->path('porta.phar'), 'php');
        } finally {
            \chmod($dir->path('porta.phar'), permissions: 0o644);
        }
    }
}
