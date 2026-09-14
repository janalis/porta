<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\PortaHome;
use Porta\Tests\Support\PreservesPortaHome;

final class PortaHomeTest extends TestCase
{
    use PreservesPortaHome;

    public function testBaseResolvesPortaHomeThenHome(): void
    {
        \putenv('PORTA_HOME=/tmp/porta-test-home');
        $this->assertSame('/tmp/porta-test-home', PortaHome::base());

        \putenv('PORTA_HOME');
        $home = \getenv('HOME');
        $this->assertSame(\is_string($home) && '' !== $home ? $home : null, PortaHome::base(), 'falls back to HOME');

        \putenv('PORTA_HOME='); // empty string → treated as unset, falls through to HOME
        $this->assertSame(\is_string($home) && '' !== $home ? $home : null, PortaHome::base());
    }
}
