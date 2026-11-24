<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\ComposerLayout;
use Porta\Tests\Support\TempProject;

/** Where Composer puts a project's packages and binaries, as Composer itself resolves it. */
final class ComposerLayoutTest extends TestCase
{
    /** @return iterable<string, array{string|null, string, string}> */
    public static function layouts(): iterable
    {
        yield 'no composer.json' => [null, 'vendor', 'vendor/bin'];
        yield 'no config' => ['{"name":"a/b"}', 'vendor', 'vendor/bin'];
        yield 'not JSON' => ['{', 'vendor', 'vendor/bin'];
        yield 'config not an object' => ['{"config":"lib"}', 'vendor', 'vendor/bin'];
        yield 'a blank or non-string dir is no dir' => ['{"config":{"vendor-dir":"  ","bin-dir":7}}', 'vendor', 'vendor/bin'];
        yield 'bin-dir set' => ['{"config":{"bin-dir":"bin"}}', 'vendor', 'bin'];
        yield 'the default bin-dir follows vendor-dir' => ['{"config":{"vendor-dir":"lib/deps"}}', 'lib/deps', 'lib/deps/bin'];
        yield '{$vendor-dir} in bin-dir' => ['{"config":{"vendor-dir":"lib","bin-dir":"{$vendor-dir}/tools"}}', 'lib', 'lib/tools'];
        yield 'spelled loosely' => ['{"config":{"vendor-dir":" ./lib/ ","bin-dir":"lib/../bin/"}}', 'lib', 'bin'];
        yield 'an absolute dir inside the project' => ['{"config":{"bin-dir":"%root%/tools"}}', 'vendor', 'tools'];
        yield 'dirs outside the project: the defaults inside it' => ['{"config":{"vendor-dir":"/opt/deps","bin-dir":"../bin"}}', 'vendor', 'vendor/bin'];
        yield 'a bin-dir outside, under a vendor-dir inside' => ['{"config":{"vendor-dir":"lib","bin-dir":"/usr/local/bin"}}', 'lib', 'lib/bin'];
    }

    #[DataProvider('layouts')]
    public function testTheLayoutAComposerJsonGives(?string $json, string $vendor, string $bin): void
    {
        $project = new TempProject();
        if (null !== $json) {
            $project->write('composer.json', \str_replace('%root%', $project->dir, $json));
        }

        $layout = ComposerLayout::of($project->dir);

        $this->assertSame($project->path($vendor), $layout->vendorDir);
        $this->assertSame($project->path($bin), $layout->binDir);
    }

    public function testTheBinDirIsNamedRelativeToTheProject(): void
    {
        $project = new TempProject(['composer.json' => '{"config":{"bin-dir":"."}}']);
        $layout = ComposerLayout::of($project->dir);

        $this->assertSame(\rtrim($project->dir, characters: '/'), $layout->binDir, 'the root itself is inside it');
        $this->assertSame('.', $layout->binDirFrom($project->dir), 'named, not left blank');
        $this->assertSame('vendor/bin', new ComposerLayout('/p/vendor', '/p/vendor/bin')->binDirFrom('/p'));
    }

    public function testTheVendorPathIsOnlyWhatASkipByNameMisses(): void
    {
        $this->assertSame('lib/deps', new ComposerLayout('/p/lib/deps', '/p/bin')->vendorPath('/p'));
        $this->assertNull(new ComposerLayout('/p/vendor', '/p/vendor/bin')->vendorPath('/p'), 'the default');
        $this->assertNull(new ComposerLayout('/p/lib/vendor', '/p/bin')->vendorPath('/p'), 'any dir named vendor');
        $this->assertNull(new ComposerLayout('/p', '/p/bin')->vendorPath('/p'), 'the root is no folder to leave out');
    }

    /**
     * Composer's global config (`config.json` in its home) applies to every
     * project, beneath the project's own: a user who set `bin-dir` there has
     * every project's tools in it.
     */
    public function testTheGlobalConfigIsReadBeneathTheProjects(): void
    {
        $home = new TempProject(['config.json' => '{"config":{"bin-dir":"tools","vendor-dir":"deps"}}']);
        $project = new TempProject(['composer.json' => '{"config":{"vendor-dir":"lib"}}']);
        $was = \getenv('COMPOSER_HOME');
        \putenv('COMPOSER_HOME='.$home->dir);
        try {
            $layout = ComposerLayout::of($project->dir);
        } finally {
            \putenv(false === $was ? 'COMPOSER_HOME' : 'COMPOSER_HOME='.$was);
        }

        $this->assertSame($project->path('lib'), $layout->vendorDir, 'the project\'s own wins');
        $this->assertSame($project->path('tools'), $layout->binDir, 'the global one where the project is silent');
    }

    /**
     * Without COMPOSER_HOME, Composer's home is found as Composer finds it:
     * the XDG config dir's `composer` only where XDG is in use (an XDG_
     * variable set, or /etc/xdg there, as on most Linux), else `~/.composer`.
     */
    public function testComposersHomeIsFoundAsComposerFindsIt(): void
    {
        $user = new TempProject(['.composer/config.json' => '{"config":{"bin-dir":"old"}}']);
        $project = new TempProject();
        $saved = ['COMPOSER_HOME' => \getenv('COMPOSER_HOME'), 'HOME' => \getenv('HOME')];
        foreach (\array_keys(\getenv()) as $name) {
            if (!\str_starts_with($name, 'XDG_')) {
                continue;
            }
            $saved[$name] = \getenv($name);
            \putenv($name);
        }
        \putenv('COMPOSER_HOME');
        \putenv('HOME='.$user->dir);
        $bin = static fn (): string => ComposerLayout::of($project->dir)->binDir;
        try {
            $this->assertSame($project->path('old'), $bin(), '~/.composer, no XDG dir there');
            $user->write('.config/composer/config.json', content: '{"config":{"bin-dir":"xdg"}}');
            $this->assertSame($project->path(\is_dir('/etc/xdg') ? 'xdg' : 'old'), $bin(), 'the XDG dir only where XDG is in use: here, /etc/xdg decides');
            $user->write('elsewhere/composer/config.json', content: '{"config":{"bin-dir":"set"}}');
            \putenv('XDG_CONFIG_HOME='.$user->path('elsewhere'));
            $this->assertSame($project->path('set'), $bin(), 'XDG_CONFIG_HOME set: XDG in use, and where its dir is');
        } finally {
            \putenv('XDG_CONFIG_HOME');
            foreach ($saved as $name => $value) {
                \putenv(false === $value ? $name : $name.'='.$value);
            }
        }
    }

    /** A remote project's copy here: its server's global config is not this machine's, and is not read. */
    public function testARemoteProjectsCopyReadsNoGlobalConfig(): void
    {
        $home = new TempProject(['config.json' => '{"config":{"bin-dir":"tools"}}']);
        $copy = new TempProject(['.porta/remote.json' => '{"url": "ssh://example.org/srv"}']);
        $was = \getenv('COMPOSER_HOME');
        \putenv('COMPOSER_HOME='.$home->dir);
        try {
            $this->assertSame($copy->path('vendor/bin'), ComposerLayout::of($copy->dir)->binDir);
        } finally {
            \putenv(false === $was ? 'COMPOSER_HOME' : 'COMPOSER_HOME='.$was);
        }
    }
}
