<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings\Section;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Overlay\State\Toast;
use Porta\Overlay\State\ToastCenter;
use Porta\Settings\Section\CommandLineSection;
use Porta\Settings\SettingsRow;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StoreFixture;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

/**
 * The Command line page against a scratch home: HOME, PATH and the global git
 * config all point into it for the test, and are put back after — the real
 * `~/.local/bin` and `~/.gitconfig` are never touched.
 */
final class CommandLineSectionTest extends TestCase
{
    use GitBinary;
    use PreservesPath;
    use PumpsLoop;

    private TempProject $home;

    private ToastCenter $toasts;

    /** @var array<string, string|false> what the test overrode, to put back */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $this->home = new TempProject(['checkout/bin/porta' => "#!/usr/bin/env php\n"]);
        $this->env('HOME', $this->home->dir);
        $this->env('PATH', $this->home->path('.local/bin').\PATH_SEPARATOR.\dirname((string) $this->whichGit()));
        $this->env('GIT_CONFIG_GLOBAL', $this->home->path('.gitconfig'));
        $this->env('GIT_CONFIG_NOSYSTEM', '1');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            unset($_ENV[$name]);
            \putenv($name);
            if (false !== $value) {
                \putenv($name.'='.$value);
                $_ENV[$name] = $value;
            }
        }
    }

    /** Both channels: getenv() for the page, $_ENV for the git it runs (Process reads that one). */
    private function env(string $name, string $value): void
    {
        $this->saved[$name] ??= \getenv($name);
        \putenv($name.'='.$value);
        $_ENV[$name] = $value;
    }

    /** A section whose Porta is $installation (a checkout of its own by default), run as $binary. */
    private function section(?Installation $installation = null, ?string $binary = null): CommandLineSection
    {
        $tui = TestTui::create(80, 24);
        $this->toasts = new ToastCenter(new FakeScheduler(), $tui);

        return new CommandLineSection(
            new Translator('en'),
            new Notifier(new StatusBarWidget(), $tui, toasts: $this->toasts),
            $binary ?? $this->home->path('checkout/bin/porta'),
            $installation ?? new Installation(InstallMethod::Checkout, $this->home->path('checkout')),
        );
    }

    /** @return array<string, SettingsRow> */
    private static function rows(CommandLineSection $section): array
    {
        $rows = [];
        foreach ($section->rows(CommandLineSection::ID) as $row) {
            $rows[$row->key] = $row;
        }

        return $rows;
    }

    private function lastToast(): string
    {
        return $this->allToasts()[0] ?? '';
    }

    /** @return list<string> */
    private function allToasts(): array
    {
        return \array_map(static fn (Toast $t): string => $t->message->plain(), $this->toasts->toasts());
    }

    public function testItIsItsOwnPage(): void
    {
        $section = $this->section();

        $this->assertTrue($section->handles('commandLine'));
        $this->assertFalse($section->handles('general'));
        $this->assertSame('Command line', $section->pages()[0]->label);
        $this->assertSame(['cli:link', 'cli:git', 'cli:update'], \array_keys(self::rows($section)));
    }

    public function testInstallLinksThePortaCommandAndOffersItsRemoval(): void
    {
        $section = $this->section();
        $this->assertSame('Install', self::rows($section)['cli:link']->value);
        $this->assertSame('Links ~/.local/bin/porta to this Porta', self::rows($section)['cli:link']->hint, 'a path under the home reads from ~');

        $section->change(CommandLineSection::ID, 'cli:link', 'Install');

        $this->assertSame($this->home->path('checkout/bin/porta'), \readlink($this->home->path('.local/bin/porta')));
        $this->assertSame('porta is now a command: ~/.local/bin/porta', $this->lastToast());
        $rows = self::rows($section);
        $this->assertTrue($rows['cli:link']->disabled, 'nothing to do while it is current');
        $this->assertSame('Up to date', $rows['cli:link']->value);
        $this->assertSame('Remove', $rows['cli:unlink']->value);
    }

    public function testAStaleLinkIsUpdated(): void
    {
        \mkdir($this->home->path('.local/bin'), recursive: true);
        \symlink('/old/checkout/bin/porta', $this->home->path('.local/bin/porta'));
        $section = $this->section();

        $this->assertSame('Update', self::rows($section)['cli:link']->value);
        $this->assertStringContainsString('runs another Porta: /old/checkout/bin/porta', (string) self::rows($section)['cli:link']->hint);
        $section->change(CommandLineSection::ID, 'cli:link', 'Update');

        $this->assertSame($this->home->path('checkout/bin/porta'), \readlink($this->home->path('.local/bin/porta')));
    }

    public function testRemovalTakesTwoPresses(): void
    {
        $section = $this->section();
        $section->change(CommandLineSection::ID, 'cli:link', 'Install');

        $section->change(CommandLineSection::ID, 'cli:unlink', 'Remove');
        $this->assertTrue(\is_link($this->home->path('.local/bin/porta')), 'the first press only arms it');
        $this->assertTrue(self::rows($section)['cli:unlink']->danger);
        $this->assertSame('Press Enter again to remove it', $this->lastToast());

        $section->change(CommandLineSection::ID, 'cli:unlink', 'Remove');
        $this->assertFalse(\is_link($this->home->path('.local/bin/porta')));
        $this->assertSame('The porta command is removed', $this->lastToast());
        $this->assertArrayNotHasKey('cli:unlink', self::rows($section));
    }

    public function testAnyOtherPressDisarmsARemoval(): void
    {
        $section = $this->section();
        $section->change(CommandLineSection::ID, 'cli:link', 'Install');
        $section->change(CommandLineSection::ID, 'cli:unlink', 'Remove');

        $section->change(CommandLineSection::ID, 'cli:git', 'Set up');
        $section->change(CommandLineSection::ID, 'cli:unlink', 'Remove');

        $this->assertTrue(\is_link($this->home->path('.local/bin/porta')), 'armed again, not removed');
    }

    public function testAPortaInstalledAnotherWayIsShownAndLeftAlone(): void
    {
        $this->home->write('.local/bin/porta', 'a copied phar');

        $row = self::rows($this->section())['cli:link'];

        $this->assertTrue($row->disabled);
        $this->assertSame('Installed', $row->value);
        $this->assertStringContainsString('installed another way', (string) $row->hint);
    }

    public function testALocalBinOffPathIsSaid(): void
    {
        $this->env('PATH', \dirname((string) $this->whichGit()));

        $this->assertStringEndsWith(' — ~/.local/bin is not on your PATH', (string) self::rows($this->section())['cli:link']->hint);
    }

    public function testAFailureIsReported(): void
    {
        $this->home->write('.local/keep', '');
        \chmod($this->home->path('.local'), permissions: 0o555);
        try {
            $this->section()->change(CommandLineSection::ID, 'cli:link', 'Install');
        } finally {
            \chmod($this->home->path('.local'), permissions: 0o755);
        }

        $this->assertStringStartsWith('Could not do it: could not create', $this->lastToast());
    }

    public function testGitIsSetUpUpdatedAndRemoved(): void
    {
        $section = $this->section();
        $this->assertSame('Set up', self::rows($section)['cli:git']->value);

        $section->change(CommandLineSection::ID, 'cli:git', 'Set up');
        $this->assertSame("porta is now git's difftool and mergetool", $this->lastToast());
        $this->assertSame('Up to date', self::rows($section)['cli:git']->value);
        $this->assertStringContainsString($this->home->path('checkout/bin/porta'), (string) \file_get_contents($this->home->path('.gitconfig')));

        \file_put_contents($this->home->path('.gitconfig'), \str_replace('checkout', replace: 'moved', subject: (string) \file_get_contents($this->home->path('.gitconfig'))));
        $this->assertSame('Update', self::rows($section)['cli:git']->value);
        $this->assertStringContainsString('another Porta', (string) self::rows($section)['cli:git']->hint);

        $section->change(CommandLineSection::ID, 'cli:ungit', 'Remove');
        $section->change(CommandLineSection::ID, 'cli:ungit', 'Remove');
        $this->assertSame("porta is no longer git's difftool and mergetool", $this->lastToast());
        $this->assertStringNotContainsString('porta', (string) \file_get_contents($this->home->path('.gitconfig')));
    }

    public function testWithoutGitTheRowSaysSo(): void
    {
        $this->env('PATH', '/nonexistent');

        $row = self::rows($this->section())['cli:git'];

        $this->assertTrue($row->disabled);
        $this->assertSame('Unavailable', $row->value);
        $this->assertSame('git is not installed', $row->hint);
    }

    public function testACheckoutOrAProjectsDependencyIsOnlyToldHowToUpdate(): void
    {
        $section = $this->section();
        $row = self::rows($section)['cli:update'];

        $this->assertTrue($row->disabled);
        $this->assertSame('Update it from a shell: git pull --ff-only && composer install', $row->hint);
        $dependency = self::rows($this->section(new Installation(InstallMethod::ComposerProject, '/work/app')))['cli:update'];
        $this->assertTrue($dependency->disabled);
        $this->assertSame('Update it from a shell: composer update janalis/porta', $dependency->hint);
        $section->change(CommandLineSection::ID, 'cli:update', 'Update');
        $this->assertSame([], $this->toasts->toasts());
    }

    /** A stand-in phar: says $says, exits $code, and — when $replace — rewrites itself as an update would. */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the stand-in replaces itself IS what the tests vary.
    private function phar(string $says, int $code = 0, bool $replace = false): string
    {
        return $this->home->write('porta.phar', \sprintf(
            "<?php\n%sfwrite(%s, %s);\nexit(%d);\n",
            $replace ? "file_put_contents(__FILE__, str_replace('replace', 'replaced', file_get_contents(__FILE__)));\n" : '',
            0 === $code ? 'STDOUT' : 'STDERR',
            \var_export($says."\n", return: true),
            $code,
        ));
    }

    public function testAnUpdateRunsTheSelfUpdateAndSaysToRestart(): void
    {
        $phar = $this->phar('porta updated: v0.1.0 → v0.2.0.', replace: true);
        $section = $this->section(new Installation(InstallMethod::Phar, $phar), $phar);
        $this->assertSame('Replaces ~/porta.phar with the latest release', self::rows($section)['cli:update']->hint, 'under the home, from ~');

        $section->change(CommandLineSection::ID, 'cli:update', 'Update');
        $this->assertSame('Looking for a newer Porta…', $this->lastToast());
        $section->change(CommandLineSection::ID, 'cli:update', 'Update');
        $this->assertSame('An update is already running', $this->lastToast());

        // The outcome answers the "Looking…" card in its slot, under the warning.
        $this->await(fn (): bool => \in_array('porta updated: v0.1.0 → v0.2.0. Restart Porta to use it.', $this->allToasts(), strict: true), what: 'the update to finish');
        $this->assertNotContains('Looking for a newer Porta…', $this->allToasts());
    }

    public function testAnUpdateWithNothingNewSaysWhatItSaid(): void
    {
        $phar = $this->phar('porta v0.2.0 is up to date (latest release: v0.2.0).');
        $section = $this->section(new Installation(InstallMethod::Phar, $phar), $phar);

        $section->change(CommandLineSection::ID, 'cli:update', 'Update');

        $this->await(fn (): bool => \str_contains($this->lastToast(), 'up to date'), what: 'the update to finish');
        $this->assertSame('porta v0.2.0 is up to date (latest release: v0.2.0).', $this->lastToast());
    }

    public function testAFailedUpdateShowsItsErrorOnOneLine(): void
    {
        $phar = $this->phar("  [ERROR] porta: self-update: gh could not read\n          the latest release", code: 1);
        $section = $this->section(new Installation(InstallMethod::Phar, $phar), $phar);

        $section->change(CommandLineSection::ID, 'cli:update', 'Update');

        $this->await(fn (): bool => \str_contains($this->lastToast(), 'self-update'), what: 'the update to fail');
        $this->assertSame('porta: self-update: gh could not read the latest release', $this->lastToast());
    }

    public function testBrewIsNamedAsWhatTheUpdateRuns(): void
    {
        $row = self::rows($this->section(new Installation(InstallMethod::Homebrew, '/opt/homebrew/Cellar/porta/0.1.5/bin/porta')))['cli:update'];

        $this->assertFalse($row->disabled);
        $this->assertSame('Runs brew upgrade janalis/tap/porta', $row->hint);
    }

    public function testAComposerUpdateThatChangedTheInstallSaysToRestart(): void
    {
        // The stand-in porta does what `composer global update` would: rewrite Composer's record.
        $home = $this->home->path('composer-home');
        $this->home->write('composer-home/vendor/composer/installed.php', '<?php return [1];');
        $binary = $this->home->write('composer-home/vendor/bin/porta', \sprintf(
            "<?php\nfile_put_contents(%s, '<?php return [2];');\necho \"porta updated: v0.1.0 → v0.2.0.\\n\";\n",
            \var_export($home.'/vendor/composer/installed.php', return: true),
        ));
        $section = $this->section(new Installation(InstallMethod::ComposerGlobal, $home), $binary);
        $this->assertSame('Runs composer global update janalis/porta --no-interaction', self::rows($section)['cli:update']->hint);

        $section->change(CommandLineSection::ID, 'cli:update', 'Update');

        $this->await(fn (): bool => \in_array('porta updated: v0.1.0 → v0.2.0. Restart Porta to use it.', $this->allToasts(), strict: true), what: 'the update to finish');
    }

    public function testNothingHereResets(): void
    {
        $section = $this->section();
        $section->reset(CommandLineSection::ID, 'cli:link');
        $section->change(CommandLineSection::ID, 'cli:nothing', '');

        $this->assertSame([], $this->toasts->toasts());
    }

    public function testSupportedManualUpdatesUseTheSessionPreservingRestart(): void
    {
        $store = new StoreFixture();
        $restart = $store->restart();
        $section = new CommandLineSection($store->translator, $store->notifier, installation: new Installation(InstallMethod::Phar, '/porta.phar'), restart: $restart);
        $section->change(CommandLineSection::ID, 'cli:update', 'Update');
        $this->assertTrue($restart->updatesCore());
    }
}
