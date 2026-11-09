<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Tests\Support\TempProject;

/** How the running Porta was installed, read off layouts in a scratch folder — nothing real is asked. */
final class InstallationTest extends TestCase
{
    private TempProject $disk;

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->disk = new TempProject([
            'Cellar/porta/0.1.5/bin/porta' => 'PHAR 0.1.5',
            'Cellar/porta/0.1.6/bin/porta' => 'PHAR 0.1.6',
            'Downloads/porta.phar' => 'PHAR',
            'home/.composer/vendor/janalis/porta/bin/porta' => '',
            'home/.composer/vendor/composer/installed.php' => '<?php return [1];',
            'home/.config/composer/vendor/janalis/porta/bin/porta' => '',
            'home/.config/composer/vendor/composer/installed.php' => '<?php return [1];',
            'app/vendor/janalis/porta/bin/porta' => '',
            'app/vendor/composer/installed.php' => '<?php return [1];',
            'unregistered/vendor/janalis/porta/bin/porta' => '',
            'checkout/.git/HEAD' => "ref: refs/heads/main\n",
        ]);
        // /tmp is /private/tmp on macOS: detection resolves, so the expectations do.
        $this->root = (string) \realpath($this->disk->dir);
        \mkdir($this->disk->path('bin'));
        \symlink($this->disk->path('Cellar/porta/0.1.5/bin/porta'), $this->disk->path('bin/porta'));
        \symlink($this->disk->path('Downloads/porta.phar'), $this->disk->path('bin/my-porta'));
    }

    private function at(string $path): string
    {
        return $this->root.'/'.$path;
    }

    public function testAPharIsResolvedThroughItsLinkSoTheSwapLandsOnTheFile(): void
    {
        $installation = Installation::detect($this->disk->path('bin/my-porta'), '', []);

        $this->assertSame(InstallMethod::Phar, $installation->method);
        $this->assertSame($this->at('Downloads/porta.phar'), $installation->path);
        $this->assertSame([], $installation->commands());
        $this->assertSame(\hash('xxh128', data: 'PHAR'), $installation->fingerprint());
    }

    public function testAPharInHomebrewsCellarIsBrewsToUpgrade(): void
    {
        $installation = Installation::detect($this->disk->path('bin/porta'), '', []);

        $this->assertSame(InstallMethod::Homebrew, $installation->method);
        $this->assertSame($this->at('Cellar/porta/0.1.5/bin/porta'), $installation->path);
        $this->assertSame('brew upgrade janalis/tap/porta', $installation->commandLine());

        // An upgrade repoints the link at the next version's folder.
        $before = $installation->fingerprint();
        \unlink($this->disk->path('bin/porta'));
        \symlink($this->disk->path('Cellar/porta/0.1.6/bin/porta'), $this->disk->path('bin/porta'));
        $this->assertSame($this->at('Cellar/porta/0.1.5/bin/porta'), $before);
        $this->assertSame($this->at('Cellar/porta/0.1.6/bin/porta'), $installation->fingerprint());
    }

    /** @return iterable<string, array{string, array<string, string|false>}> */
    public static function composerHomes(): iterable
    {
        yield '~/.composer' => ['home/.composer', ['HOME' => '{root}/home/']];
        yield 'the XDG folder under ~/.config' => ['home/.config/composer', ['HOME' => '{root}/home', 'XDG_CONFIG_HOME' => false]];
        yield '$XDG_CONFIG_HOME' => ['home/.config/composer', ['HOME' => '/nowhere', 'XDG_CONFIG_HOME' => '{root}/home/.config']];
        yield '$COMPOSER_HOME, over the rest' => ['app', ['HOME' => '{root}/home', 'COMPOSER_HOME' => '{root}/app']];
    }

    /** @param array<string, string|false> $env */
    #[DataProvider('composerHomes')]
    public function testAPackageUnderComposersHomeIsAGlobalInstall(string $home, array $env): void
    {
        $env = \array_map(fn (string|false $value): string|false => \is_string($value) ? \str_replace('{root}', $this->disk->dir, $value) : $value, $env);

        $installation = Installation::detect('', $this->disk->path($home.'/vendor/janalis/porta'), $env);

        $this->assertSame(InstallMethod::ComposerGlobal, $installation->method);
        $this->assertSame($this->at($home), $installation->path);
        $this->assertSame('composer global update janalis/porta --no-interaction', $installation->commandLine());
    }

    public function testAPackageElsewhereIsSomeProjectsDependency(): void
    {
        $installation = Installation::detect('', $this->disk->path('app/vendor/janalis/porta'), ['HOME' => $this->disk->path('home'), 'COMPOSER_HOME' => '']);

        $this->assertSame(InstallMethod::ComposerProject, $installation->method);
        $this->assertSame($this->at('app'), $installation->path);
        $this->assertSame('composer update janalis/porta --working-dir='.$this->at('app'), $installation->commandLine());
        $this->assertFalse($installation->method->updatesItself());

        $before = $installation->fingerprint();
        $this->disk->write('app/vendor/composer/installed.php', '<?php return [2];');
        $this->assertNotSame($before, $installation->fingerprint());
    }

    public function testAVendorFolderComposerNeverWroteIsNotAComposerInstall(): void
    {
        $this->assertSame(InstallMethod::Checkout, Installation::detect('', $this->disk->path('unregistered/vendor/janalis/porta'), [])->method);
    }

    public function testAnythingElseIsACheckoutToPull(): void
    {
        $installation = Installation::detect('', $this->disk->path('checkout'), []);

        $this->assertSame(InstallMethod::Checkout, $installation->method);
        $this->assertSame(
            \sprintf('git -C %1$s pull --ff-only && composer install --working-dir=%1$s', $this->at('checkout')),
            $installation->commandLine(),
        );
        $this->assertSame('', $installation->fingerprint());
    }

    public function testAPathAShellWouldSplitIsQuoted(): void
    {
        $installation = new Installation(InstallMethod::ComposerProject, '/My Projects/app');

        $this->assertSame("composer update janalis/porta '--working-dir=/My Projects/app'", $installation->commandLine());
    }

    public function testOnlyThePharBrewAndAGlobalComposerUpdateThemselves(): void
    {
        $this->assertSame(
            [InstallMethod::Phar, InstallMethod::Homebrew, InstallMethod::ComposerGlobal],
            \array_values(\array_filter(InstallMethod::cases(), static fn (InstallMethod $method): bool => $method->updatesItself())),
        );
    }
}
