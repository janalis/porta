<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Console\SelfUpdate;
use Porta\Tests\Support\TempProject;

/**
 * The self-update against a scripted `gh` / `curl` / `php`: no network, no
 * credentials — what is asserted is what it asks for and what it does with
 * the answers.
 */
final class SelfUpdateTest extends TestCase
{
    /** @var list<array{list<string>, ?string}> every command run, with its stdin */
    private array $ran = [];

    /** Whether `gh` is installed in the scripted world. */
    private bool $gh = true;

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
            if ('gh' === $bin && '--version' === $command[1]) {
                return $this->gh ? [0, "gh version 2.60.0\n"] : [127, ''];
            }
            if ('gh' === $bin && 'view' === $command[2]) {
                return [0, "v1.3.0\n"];
            }
            if ('gh' === $bin && 'download' === $command[2]) {
                $dir = $command[\array_search('--dir', $command, strict: true) + 1];
                $this->writeRelease($dir);

                return [$this->downloadFails, ''];
            }
            if ('curl' === $bin) {
                return $this->curl($command);
            }

            // The new phar, run before it replaces anything.
            return [$this->newPharRuns, "porta v1.3.0\n"];
        };
    }

    private function writeRelease(string $dir): void
    {
        \file_put_contents($dir.'/porta.phar', $this->pharBytes);
        \file_put_contents($dir.'/porta.phar.sha256', ($this->shaOverride ?? \hash('sha256', $this->pharBytes)).'  porta.phar'."\n");
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function curl(array $command): array
    {
        $url = (string) \end($command);
        if (\str_ends_with($url, '/releases/latest')) {
            return [0, (string) \json_encode(['tag_name' => 'v1.3.0'], \JSON_THROW_ON_ERROR)];
        }
        if (\str_contains($url, '/releases/tags/')) {
            return [
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
            ];
        }
        $out = $command[\array_search('-o', $command, strict: true) + 1];
        \file_put_contents($out, \str_ends_with($out, '.sha256') ? \hash('sha256', $this->pharBytes)."  porta.phar\n" : $this->pharBytes);

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

    public function testTheLatestReleaseComesFromGhWhenItIsThere(): void
    {
        $this->assertSame('v1.3.0', new SelfUpdate($this->runner(), [])->latest());
        $this->assertSame(['gh', 'release', 'view', '--repo', 'janalis/porta', '--json', 'tagName', '--jq', '.tagName'], $this->ran[1][0]);
    }

    public function testWithoutGhTheTokenReachesTheApiOnStdinNeverInTheArguments(): void
    {
        $this->gh = false;

        $this->assertSame('v1.3.0', new SelfUpdate($this->runner(), ['GITHUB_TOKEN' => self::fake('secret')])->latest());

        [$command, $stdin] = $this->ran[1];
        $this->assertSame('curl', $command[0]);
        $this->assertStringContainsString('--proto =https --proto-redir =https', \implode(' ', $command), 'never downgraded to plain http');
        $this->assertStringContainsString('Authorization: Bearer '.self::fake('secret'), (string) $stdin);
        $this->assertStringNotContainsString(self::fake('secret'), \implode(' ', $command), 'a token in argv is visible to every process list');
    }

    public function testGhTokenWinsOverGithubToken(): void
    {
        $this->gh = false;

        new SelfUpdate($this->runner(), ['GH_TOKEN' => self::fake('gh'), 'GITHUB_TOKEN' => self::fake('github')])->latest();

        $this->assertStringContainsString('Bearer '.self::fake('gh'), (string) $this->ran[1][1]);
    }

    public function testWithNeitherGhNorATokenItSaysWhatToInstall(): void
    {
        $this->gh = false;

        $this->expectExceptionMessageIsOrContains('self-update needs the gh CLI');
        new SelfUpdate($this->runner(), ['GH_TOKEN' => ''])->latest();
    }

    public function testAnUnreadableReleaseIsReported(): void
    {
        $runner = static fn (array $command, ?string $stdin = null): array => 'gh' === $command[0] && '--version' === $command[1] ? [0, ''] : [1, ''];

        $this->expectExceptionMessageIsOrContains('gh could not read the latest release');
        new SelfUpdate($runner, [])->latest();
    }

    public function testAnApiThatDoesNotAnswerIsReported(): void
    {
        $runner = static fn (array $command, ?string $stdin = null): array => [127, ''];

        $this->expectExceptionMessageIsOrContains('could not reach the releases of janalis/porta');
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

    public function testInstallWithATokenDownloadsTheTwoAssetsOnly(): void
    {
        $this->gh = false;
        $dir = $this->installed();

        new SelfUpdate($this->runner(), ['GH_TOKEN' => self::fake('t')])->install('v1.3.0', $dir->path('porta.phar'), 'php');

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
            'could not download v1.3.0',
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

    public function testATokenDownloadThatFailsIsReported(): void
    {
        $this->gh = false;
        $this->downloadFails = 22;
        $dir = $this->installed();

        $this->expectExceptionMessageIsOrContains('could not download porta.phar of v1.3.0');
        new SelfUpdate($this->runner(), ['GH_TOKEN' => self::fake('t')])->install('v1.3.0', $dir->path('porta.phar'), 'php');
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
