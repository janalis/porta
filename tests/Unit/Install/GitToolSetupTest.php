<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Install\GitToolSetup;
use Porta\Install\GitToolStatus;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

/**
 * The setup against a REAL git, whose global config is a scratch file handed to
 * each git through `GIT_CONFIG_GLOBAL` — never the user's, and never through
 * the process environment the rest of the suite shares.
 */
final class GitToolSetupTest extends TestCase
{
    use GitBinary;

    private TempProject $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $this->home = new TempProject();
    }

    private function tool(string $porta = '/opt/porta/bin/porta'): GitToolSetup
    {
        return new GitToolSetup($porta, $this->git(...));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function git(array $args): array
    {
        $process = new Process([(string) $this->whichGit(), ...$args], env: ['GIT_CONFIG_GLOBAL' => $this->home->path('.gitconfig'), 'GIT_CONFIG_NOSYSTEM' => '1']);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput()];
    }

    private function config(): string
    {
        return \is_file($this->home->path('.gitconfig')) ? (string) \file_get_contents($this->home->path('.gitconfig')) : '';
    }

    public function testNothingSetIsAbsentEvenWithoutAConfigFile(): void
    {
        $this->assertSame(GitToolStatus::Absent, $this->tool()->status());
    }

    public function testAnotherToolAloneIsStillAbsent(): void
    {
        $this->git(['config', '--global', 'diff.tool', 'vimdiff']);

        $this->assertSame(GitToolStatus::Absent, $this->tool()->status());
    }

    public function testApplyMakesItCurrent(): void
    {
        $this->assertNull($this->tool()->apply());

        $this->assertSame(GitToolStatus::Current, $this->tool()->status());
    }

    public function testASetupNamingAnotherBinaryIsStaleUntilWrittenAgain(): void
    {
        $this->tool('/old/checkout/bin/porta')->apply();

        $this->assertSame(GitToolStatus::Stale, $this->tool()->status());
        $this->tool()->apply();
        $this->assertSame(GitToolStatus::Current, $this->tool()->status());
    }

    public function testHalfASetupIsStale(): void
    {
        $this->git(['config', '--global', 'merge.tool', 'porta']);

        $this->assertSame(GitToolStatus::Stale, $this->tool()->status());
    }

    public function testRemoveTakesEveryPortaKeyAway(): void
    {
        $this->tool()->apply();

        $this->assertNull($this->tool()->remove());

        $this->assertSame(GitToolStatus::Absent, $this->tool()->status());
        $this->assertStringNotContainsString('porta', $this->config());
    }

    public function testRemoveLeavesAToolTheUserPickedSince(): void
    {
        $this->tool()->apply();
        $this->git(['config', '--global', 'diff.tool', 'vimdiff']);

        $this->assertNull($this->tool()->remove());

        $this->assertSame([0, "vimdiff\n"], $this->git(['config', '--global', 'diff.tool']));
        $this->assertSame(1, $this->git(['config', '--global', 'merge.tool'])[0], 'the one still naming porta is gone');
    }

    public function testRemoveWithNothingSetDoesNothing(): void
    {
        $ran = [];
        $git = function (array $args) use (&$ran): array {
            $ran[] = $args;

            return $this->git($args);
        };

        $this->assertNull(new GitToolSetup('/opt/porta', $git)->remove());
        $this->assertCount(1, $ran, 'only the read');
    }

    public function testWithoutGitItIsUnavailable(): void
    {
        $this->assertSame(GitToolStatus::Unavailable, new GitToolSetup('/opt/porta', static fn (array $args): array => [127, ''])->status());
    }

    public function testARemovalGitRefusesIsNamed(): void
    {
        $this->tool()->apply();
        $git = fn (array $args): array => \in_array('--remove-section', $args, strict: true) ? [128, ''] : $this->git($args);

        $this->assertSame('difftool.porta', new GitToolSetup('/opt/porta/bin/porta', $git)->remove());
    }
}
