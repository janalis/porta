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

    public function testTildeShortensAPathUnderHomeOnly(): void
    {
        \putenv('PORTA_HOME=/home/ada');

        $this->assertSame('~/code/ide', PortaHome::tilde('/home/ada/code/ide'));
        $this->assertSame('/home/adam/code', PortaHome::tilde('/home/adam/code'), 'a sibling that shares the prefix is not under home');
        $this->assertSame('/srv/app', PortaHome::tilde('/srv/app'));
        $this->assertSame('/home/ada', PortaHome::tilde('/home/ada'), 'home itself is left alone: `~` alone reads as nothing');
    }

    public function testExpandIsTildesInverse(): void
    {
        \putenv('PORTA_HOME=/home/ada');

        $this->assertSame('/home/ada/code/ide', PortaHome::expand('~/code/ide'));
        $this->assertSame('/srv/app', PortaHome::expand('/srv/app'));
        $this->assertSame('', PortaHome::expand(''));
        $this->assertSame('/home/ada', PortaHome::expand('~'));
        $this->assertSame('~bob/code', PortaHome::expand('~bob/code'), "bob's home is not ours");
    }

    public function testWithNoHomeNothingIsShortenedOrExpanded(): void
    {
        $home = \getenv('HOME');
        \putenv('PORTA_HOME');
        \putenv('HOME');
        try {
            $this->assertNull(PortaHome::base());
            $this->assertSame('/home/ada/code', PortaHome::tilde('/home/ada/code'));
            $this->assertSame('~/code', PortaHome::expand('~/code'));
        } finally {
            \putenv(false === $home ? 'HOME' : 'HOME='.$home);
        }
    }
}
