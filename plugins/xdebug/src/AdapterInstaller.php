<?php

declare(strict_types=1);

namespace Porta\Xdebug;

use Porta\Support\AsyncProcess;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Downloads the pinned vscode-php-debug release and puts its `extension/`
 * where {@see AdapterLocator} looks.
 *
 * The release publishes no checksum file, so the plugin carries one: a
 * download whose SHA-256 is not the pinned one is thrown away unread. Only
 * the archive's `extension/` is unpacked, into a scratch directory beside the
 * target, and moved into place once the adapter's script is there — so a
 * failure at any step leaves the last good install, or nothing, never half of
 * one. curl and unzip run as subprocesses: the UI never waits on the network.
 */
final readonly class AdapterInstaller
{
    /**
     * @param list<string> $fetch  how to download: the argv curl is run with before `-o <file> <url>`
     * @param string       $url    where the release is
     * @param string       $sha256 what it must hash to
     * @param string       $target the directory the adapter's `extension/` lands in
     */
    public function __construct(
        private array $fetch,
        private string $url,
        private string $sha256,
        private string $target,
        private Filesystem $fs = new Filesystem(),
    ) {}

    /** @param \Closure(?string): void $done null once installed, else why not */
    public function install(\Closure $done): void
    {
        $scratch = $this->target.'.part-'.\bin2hex(\random_bytes(4));
        try {
            $this->fs->mkdir($scratch);
        } catch (IOExceptionInterface $error) {
            $done($error->getMessage()); // nothing made yet to clean up

            return;
        }
        $archive = $scratch.'/php-debug.vsix';
        $fail = function (string $why) use ($scratch, $done): void {
            $this->fs->remove($scratch);
            $done($why);
        };
        $this->run([...$this->fetch, '-o', $archive, $this->url], $scratch, $fail, function () use ($archive, $scratch, $fail, $done): void {
            if (!\hash_equals($this->sha256, (string) \hash_file('sha256', $archive))) {
                $fail('the download is not the release it should be (its SHA-256 differs)');

                return;
            }
            $this->run(['unzip', '-q', $archive, 'extension/*', '-d', $scratch.'/unpacked'], $scratch, $fail, function () use ($scratch, $fail, $done): void {
                if (!\is_file($scratch.'/unpacked/extension/out/phpDebug.js')) {
                    $fail('the release holds no extension/out/phpDebug.js');

                    return;
                }
                // An adapter running from the old copy, a target on another
                // device: said, and the button freed, rather than thrown into the loop.
                try {
                    $this->fs->remove($this->target);
                    $this->fs->rename($scratch.'/unpacked', $this->target);
                } catch (IOExceptionInterface $error) {
                    $fail($error->getMessage());

                    return;
                }
                $this->fs->remove($scratch);
                $done(null);
            });
        });
    }

    /**
     * @param list<string>            $command
     * @param \Closure(string): void  $fail
     * @param \Closure(): void        $then
     */
    private function run(array $command, string $cwd, \Closure $fail, \Closure $then): void
    {
        $started = new AsyncProcess($command, $cwd, timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT)->start(
            static fn (int $code) => 0 === $code ? $then() : $fail(\sprintf('%s exited with code %d', $command[0], $code)),
        );
        if (!$started) {
            $fail(\sprintf('%s is not available', $command[0]));
        }
    }
}
