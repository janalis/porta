<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Filesystem\RemoteStamp;

/**
 * The verbs every {@see \Porta\Filesystem\RemoteTransport} must have, doing
 * nothing — for a stand-in whose test is about the optional ones it adds
 * (walk, a command line), or about leaving them out.
 */
trait NoOpTransportVerbs
{
    public function list(string $path, \Closure $done): void {}

    public function fetch(string $path, \Closure $done): void {}

    public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void {}

    public function mkdir(string $path, \Closure $done): void {}

    public function rename(string $from, string $to, \Closure $done): void {}

    public function remove(string $path, bool $isDir, \Closure $done): void {}

    public function close(): void {}
}
