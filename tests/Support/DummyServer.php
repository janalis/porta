<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * One of the PHP servers in `Servers/`, run as its own process on a free
 * port of 127.0.0.1, serving $root: an FTP server the real curl talks to
 * ({@see ftp()}), or the far end of the ssh stand-in ({@see ssh()}).
 *
 * {@see stop()} takes it away — the next connection is refused, as a server
 * gone down refuses it — and {@see start()} brings it back on the SAME port,
 * so whatever was connected to it carries on. Killed when the test lets go
 * of it.
 */
final class DummyServer
{
    /** The ssh stand-in's client: what the ssh plugin is told is ssh. */
    public const string SSH_CLIENT = __DIR__.'/Servers/ssh-client';

    public int $port = 0;

    /** @var resource|null */
    private $process;

    private readonly string $portFile;

    /** @param list<string> $extra what the script takes after its port file */
    private function __construct(
        private readonly string $script,
        public readonly string $root,
        private readonly array $extra = [],
    ) {
        $this->portFile = \sys_get_temp_dir().'/porta-server-'.\bin2hex(\random_bytes(6));
        $this->start();
    }

    /** An FTP server serving $root as `/`; with $password, the only one it lets in. */
    public static function ftp(string $root, #[\SensitiveParameter] ?string $password = null): self
    {
        return new self(__DIR__.'/Servers/ftp-server', $root, null === $password ? [] : [$password]);
    }

    /** The far end of the ssh stand-in, running commands in $root. */
    public static function ssh(string $root): self
    {
        return new self(__DIR__.'/Servers/ssh-server', $root);
    }

    public function start(): void
    {
        if (null !== $this->process) {
            return;
        }
        \file_put_contents($this->portFile, data: '');
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, $this->script, $this->root, (string) $this->port, $this->portFile, ...$this->extra],
            [
                ['file', '/dev/null', 'r'],
                ['file', '/dev/null', 'w'],
                ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );
        \assert(false !== $process, description: 'php runs');
        $this->process = $process;
        $deadline = \microtime(true) + Budget::seconds(10.0);
        while ('' === (string) \file_get_contents($this->portFile)) {
            if (\microtime(true) > $deadline) {
                throw new \RuntimeException('the server did not start: '.$this->script);
            }
            \usleep(10_000);
        }
        $this->port = (int) \file_get_contents($this->portFile);
    }

    /** Take the server away: what connects next is refused. */
    public function stop(): void
    {
        if (null === $this->process) {
            return;
        }
        \proc_terminate($this->process, signal: 9);
        \proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
        if (\is_file($this->portFile)) {
            \unlink($this->portFile);
        }
    }
}
