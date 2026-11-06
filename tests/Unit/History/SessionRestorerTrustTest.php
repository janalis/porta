<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\History\SessionRestorer;
use Porta\History\SessionStore;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A restricted project's `.porta/session.json` is as likely the clone's as
 * the user's: it reopens the project's own files and nothing elsewhere on the
 * machine. A trusted project's reopens what the user had open, wherever it is.
 */
final class SessionRestorerTrustTest extends TestCase
{
    use BootsIde;
    use UntrustsProjects;

    private TempProject $elsewhere;

    protected function setUp(): void
    {
        $this->elsewhere = new TempProject(['id_rsa' => "secret\n"]);
    }

    /** @return iterable<string, array{\Closure(string, string): string}> how the session names the file outside */
    public static function outside(): iterable
    {
        yield 'by its absolute path' => [static fn (string $root, string $outside): string => $outside];
        yield 'climbing out of the project' => [static fn (string $root, string $outside): string => $root.'/src/../../'.\basename(\dirname($outside)).'/'.\basename($outside)];
        yield 'through a link inside it' => [static function (string $root, string $outside): string {
            \symlink($outside, $root.'/src/key');

            return $root.'/src/key';
        }];
        yield 'beside it, under a name that starts with its own' => [static function (string $root, string $outside): string {
            \mkdir($root.'-copy');
            \copy($outside, $root.'-copy/id_rsa');

            return $root.'-copy/id_rsa';
        }];
    }

    #[After]
    protected function removeTheCopyBeside(): void
    {
        if ($this->booted) {
            new Filesystem()->remove((string) \realpath($this->project->dir).'-copy');
        }
    }

    /** @param \Closure(string, string): string $named */
    #[DataProvider('outside')]
    public function testARestrictedProjectsSessionReopensOnlyItsOwnFiles(\Closure $named): void
    {
        $this->untrustProjects();
        $restored = $this->restored($named);

        $this->assertSame([$this->own()], $restored, 'its own file, and not the one elsewhere');
    }

    /** @param \Closure(string, string): string $named */
    #[DataProvider('outside')]
    public function testATrustedProjectsSessionReopensWhatTheUserHadOpenWhereverItIs(\Closure $named): void
    {
        $this->assertCount(2, $this->restored($named), 'the file elsewhere came back too');
    }

    /** A window that left the session as it found it writes nothing: the other window's save stands. */
    public function testAnIdleWindowDoesNotOverwriteTheSession(): void
    {
        $this->bootIde(['composer.json' => '{}', 'src/A.php' => "<?php\n", 'src/B.php' => "<?php\n"]);
        $store = new SessionStore($this->project->dir);
        $store->save([['path' => $this->own(), 'line' => 0, 'col' => 0]], null);
        $restorer = Services::resolve($this->ide, SessionRestorer::class);
        $restorer->restore();

        $other = [['path' => (string) \realpath($this->project->path('src/B.php')), 'line' => 0, 'col' => 0, 'pinned' => false]];
        $store->save($other, null); // the other window quit first
        $restorer->save();

        $this->assertSame([(string) \realpath($this->project->path('src/B.php'))], \array_column($store->load()['buffers'] ?? [], 'path'));
    }

    private function own(): string
    {
        return (string) \realpath($this->project->path('src/A.php'));
    }

    /**
     * @param \Closure(string, string): string $named
     *
     * @return list<string> the paths open once the session is restored
     */
    private function restored(\Closure $named): array
    {
        $this->bootIde(['composer.json' => '{}', 'src/A.php' => "<?php\n"]);
        $root = (string) \realpath($this->project->dir);
        new SessionStore($this->project->dir)->save([
            ['path' => $this->own(), 'line' => 0, 'col' => 0],
            ['path' => $named($root, (string) \realpath($this->elsewhere->path('id_rsa'))), 'line' => 0, 'col' => 0],
        ], null);

        $this->assertTrue(Services::resolve($this->ide, SessionRestorer::class)->restore());

        return \array_values(\array_map(static fn (Buffer $buffer): string => $buffer->path, Services::resolve($this->ide, BufferManager::class)->all()));
    }
}
