<?php

declare(strict_types=1);

namespace Porta\Xdebug\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Xdebug\AdapterInstaller;

/**
 * The download, the check and the unpacking — against a fixture archive and
 * a curl stand-in, so nothing reaches the network.
 */
final class AdapterInstallerTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private const string FIXTURES = __DIR__.'/fixtures';

    private TempProject $home;

    protected function setUp(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // unzip
        $this->home = new TempProject();
    }

    private function target(): string
    {
        return $this->home->path('tools/php-debug/1.40.2');
    }

    /** @param list<string>|null $fetch */
    private function install(string $url, ?string $sha256 = null, ?array $fetch = null): ?string
    {
        $outcome = [];
        new AdapterInstaller(
            $fetch ?? [\PHP_BINARY, self::FIXTURES.'/fetch.php'],
            $url,
            $sha256 ?? (string) \hash_file('sha256', self::FIXTURES.'/php-debug.vsix'),
            $this->target(),
        )->install(static function (?string $failure) use (&$outcome): void {
            $outcome[] = $failure;
        });
        $this->await(static function () use (&$outcome): bool {
            return [] !== $outcome;
        }, what: 'the install to end');
        $this->assertCount(1, $outcome, 'it ends once');

        return $outcome[0];
    }

    /** @return list<string> what is left beside the target: no scratch directory may be */
    private function leftovers(): array
    {
        $entries = \scandir(\dirname($this->target()));

        return \array_values(\array_diff(false === $entries ? [] : $entries, ['.', '..', '1.40.2']));
    }

    public function testTheReleaseLandsWithOnlyItsExtension(): void
    {
        $this->home->write('tools/php-debug/1.40.2/stale.txt', 'from before');

        $this->assertNull($this->install(self::FIXTURES.'/php-debug.vsix'));

        $this->assertFileExists($this->target().'/extension/out/phpDebug.js');
        $this->assertFileDoesNotExist($this->target().'/extension.vsixmanifest', 'nothing outside extension/');
        $this->assertFileDoesNotExist($this->target().'/stale.txt', 'the last install is replaced whole');
        $this->assertSame([], $this->leftovers());
    }

    public function testWhatIsNotTheReleaseIsThrownAway(): void
    {
        $this->home->write('tools/php-debug/1.40.2/extension/out/phpDebug.js', 'the good one');

        $this->assertSame('the download is not the release it should be (its SHA-256 differs)', $this->install(self::FIXTURES.'/php-debug.vsix', \str_repeat('0', times: 64)));
        $this->assertSame('the release holds no extension/out/phpDebug.js', $this->install(self::FIXTURES.'/no-adapter.vsix', (string) \hash_file('sha256', self::FIXTURES.'/no-adapter.vsix')));
        $this->assertSame(\PHP_BINARY.' exited with code 22', $this->install('fail'));
        $this->assertSame('no-such-curl is not available', $this->install(self::FIXTURES.'/php-debug.vsix', fetch: ['no-such-curl']));

        $this->assertSame('the good one', \file_get_contents($this->target().'/extension/out/phpDebug.js'), 'a failure leaves the last good install');
        $this->assertSame([], $this->leftovers(), 'and no scratch behind it');
    }
}
