<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\TestCase;
use Porta\Presence\Identity;

/** The name the others see, never empty — and whether they are told at all. */
final class IdentityTest extends TestCase
{
    public function testTheSettingElseTheLoginNameElseAnonymous(): void
    {
        $user = \getenv('USER');
        try {
            $identity = new Identity();
            \putenv('USER=ada');
            $this->assertSame('ada', $identity->name());
            $identity->rename(" \x07Ada Lovelace ");
            $this->assertSame('Ada Lovelace', $identity->name());
            $identity->rename("\x07");
            \putenv('USER=');
            $this->assertSame('anonymous', $identity->name(), 'a name of nothing is no name');
            \putenv('USER');
            $this->assertSame('anonymous', $identity->name());
        } finally {
            \putenv(false === $user ? 'USER' : 'USER='.$user);
        }
    }

    public function testSharingIsOnUntilSaidOtherwise(): void
    {
        $identity = new Identity();
        $this->assertTrue($identity->sharing());
        $identity->share(false);
        $this->assertFalse($identity->sharing());
    }
}
