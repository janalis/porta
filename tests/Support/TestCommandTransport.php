<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Filesystem\CommandTransport;
use Porta\Filesystem\RemoteStamp;

/** The process machinery of a command transport, its protected verbs made public; the file verbs do nothing. */
final class TestCommandTransport extends CommandTransport
{
    /**
     * @param list<string>                         $command
     * @param \Closure(int, string, string): void $done
     */
    public function exec(array $command, ?string $stdin, \Closure $done): void
    {
        $this->run($command, $stdin, $done);
    }

    public function atMost(int $limit): void
    {
        $this->runAtMost($limit);
    }

    public static function say(int $code, string $stderr): string
    {
        return self::failure($code, $stderr);
    }

    public function list(string $path, \Closure $done): void {}

    public function fetch(string $path, \Closure $done): void {}

    public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void {}

    public function mkdir(string $path, \Closure $done): void {}

    public function rename(string $from, string $to, \Closure $done): void {}

    public function remove(string $path, bool $isDir, \Closure $done): void {}
}
