<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Console\ReleasePrepareCommand;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `release:prepare` over a throwaway checkout: the version it picks from the
 * tags, what it writes into the changelog, `Ide.php` and the notes, and every
 * input it refuses without touching a file.
 */
final class ReleasePrepareCommandTest extends TestCase
{
    private const string CHANGELOG = "# Changelog\n\nIntro.\n\n## [Unreleased]\n\n### Added\n\n- Thing.\n\n## [0.2.3] - 2026-01-01\n\n- Old.\n";

    private const string IDE = "<?php\nfinal class Ide\n{\n    public const string VERSION = '0.2.3';\n}\n";

    /** @param list<string> $tags */
    private static function project(array $tags = ['v0.1.0', 'v0.2.3', 'v0.10.0-rc1', 'vnext', 'v00.1.0'], string $changelog = self::CHANGELOG, string $ide = self::IDE): TempProject
    {
        $project = new TempProject(['CHANGELOG.md' => $changelog, 'src/Ide.php' => $ide]);
        $project->initGit();
        $project->commitAll('init');
        foreach ($tags as $tag) {
            $project->git('tag', $tag);
        }

        return $project;
    }

    /** @param array<string, string> $options */
    private static function prepare(TempProject $project, string $version, array $options = ['--date' => '2026-10-08']): CommandTester
    {
        $tester = new CommandTester(new ReleasePrepareCommand($project->dir));
        $tester->execute(['version' => $version, 'notes' => $project->path('notes.md'), ...$options]);

        return $tester;
    }

    public function testAMinorBumpReleasesTheUnreleasedSection(): void
    {
        $project = self::project();
        $tester = self::prepare($project, 'minor');

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame("version=0.3.0\ntag=v0.3.0\n", $tester->getDisplay(), 'neither a pre-release, a non-version tag, nor the tag git lists last');
        $this->assertSame(
            "# Changelog\n\nIntro.\n\n## [Unreleased]\n\n## [0.3.0] - 2026-10-08\n\n### Added\n\n- Thing.\n\n## [0.2.3] - 2026-01-01\n\n- Old.\n",
            \file_get_contents($project->path('CHANGELOG.md')),
        );
        $this->assertSame("### Added\n\n- Thing.\n", \file_get_contents($project->path('notes.md')));
        $this->assertSame(\str_replace(search: "'0.2.3'", replace: "'0.3.0'", subject: self::IDE), \file_get_contents($project->path('src/Ide.php')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function bumps(): iterable
    {
        yield 'patch' => ['patch', '0.2.4'];
        yield 'major' => ['major', '1.0.0'];
        yield 'explicit' => ['0.2.10', '0.2.10'];
    }

    #[DataProvider('bumps')]
    public function testTheVersionIsBumpedFromTheNewestTagOrGiven(string $request, string $expected): void
    {
        $tester = self::prepare(self::project(), $request);

        $this->assertSame("version={$expected}\ntag=v{$expected}\n", $tester->getDisplay());
    }

    public function testTheFirstReleaseOfAChangelogWithNothingBelowIt(): void
    {
        $project = self::project(tags: [], changelog: "# Changelog\n\n## [Unreleased]\n\n- First.\n");
        $tester = self::prepare($project, 'patch', options: []);

        $this->assertSame("version=0.0.1\ntag=v0.0.1\n", $tester->getDisplay(), 'no tag counts as 0.0.0');
        $this->assertSame("# Changelog\n\n## [Unreleased]\n\n## [0.0.1] - ".\date('Y-m-d')."\n\n- First.\n", \file_get_contents($project->path('CHANGELOG.md')));
    }

    /** @return iterable<string, array{string, string, string, array<string, string>}> */
    public static function refusals(): iterable
    {
        yield 'a word' => ['0.2.3', self::CHANGELOG, 'next', ['--date' => '2026-10-08']];
        yield 'the newest tag again' => ['0.2.3', self::CHANGELOG, '0.2.3', ['--date' => '2026-10-08']];
        yield 'below the newest tag' => ['0.2.3', self::CHANGELOG, '0.1.9', ['--date' => '2026-10-08']];
        yield 'no Unreleased section' => ['0.2.3', "# Changelog\n\n## [0.2.3] - 2026-01-01\n", 'patch', ['--date' => '2026-10-08']];
        yield 'an empty Unreleased section' => ['0.2.3', "# Changelog\n\n## [Unreleased]\n\n## [0.2.3] - 2026-01-01\n", 'patch', ['--date' => '2026-10-08']];
        yield 'no VERSION constant' => ['', self::CHANGELOG, 'patch', ['--date' => '2026-10-08']];
        yield 'a malformed date' => ['0.2.3', self::CHANGELOG, 'patch', ['--date' => '2 Nov 2026']];
        yield 'a date with a tail' => ['0.2.3', self::CHANGELOG, 'patch', ['--date' => '2026-10-08T10:00']];
        yield 'a date with a head' => ['0.2.3', self::CHANGELOG, 'patch', ['--date' => 'on 2026-10-08']];
        yield 'a tag, not a version' => ['0.2.3', self::CHANGELOG, 'v0.3.0', ['--date' => '2026-10-08']];
    }

    /** @param array<string, string> $options */
    #[DataProvider('refusals')]
    public function testItRefusesWithoutWritingAnything(string $ideVersion, string $changelog, string $version, array $options): void
    {
        $ide = '' === $ideVersion ? "<?php\n" : \str_replace("'0.2.3'", "'{$ideVersion}'", self::IDE);
        $project = self::project(changelog: $changelog, ide: $ide);
        $tester = self::prepare($project, $version, $options);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringStartsWith('release:prepare: ', $tester->getDisplay());
        $this->assertSame($changelog, \file_get_contents($project->path('CHANGELOG.md')));
        $this->assertSame($ide, \file_get_contents($project->path('src/Ide.php')));
        $this->assertFileDoesNotExist($project->path('notes.md'));
    }

    public function testOutsideAGitCheckoutItRefusesRatherThanCountFromNoTag(): void
    {
        $project = new TempProject(['CHANGELOG.md' => self::CHANGELOG, 'src/Ide.php' => self::IDE]);
        $tester = self::prepare($project, 'patch');

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame('release:prepare: git could not list the tags of '.$project->dir." — the newest one is what the version must be above\n", $tester->getDisplay());
        $this->assertSame(self::CHANGELOG, \file_get_contents($project->path('CHANGELOG.md')));
    }

    /**
     * What a source checkout's `porta --version` says is the newest version
     * the changelog has released: the release workflow sets both at once, so
     * they only part when one is edited by hand.
     */
    public function testIdeVersionIsTheNewestReleaseInTheChangelog(): void
    {
        $changelog = (string) \file_get_contents(\dirname(__DIR__, levels: 3).'/CHANGELOG.md');
        $match = [];
        \preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match);

        $this->assertSame($match[1] ?? null, Ide::VERSION);
    }
}
