<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\ToolManager;

/** The argv each manager is asked for, installing and updating. */
final class ToolManagerTest extends TestCase
{
    public function testEachManagerInstallsAndUpdatesItsOwnWay(): void
    {
        $this->assertSame(['require', '--dev', 'a/b'], ToolManager::Composer->installArgs('a/b'));
        $this->assertSame(['update', 'a/b', '--with-dependencies'], ToolManager::Composer->updateArgs('a/b'));
        $this->assertSame(['install', 'x'], ToolManager::Brew->installArgs('x'));
        $this->assertSame(['upgrade', 'x'], ToolManager::Brew->updateArgs('x'));
        $this->assertSame(['install', '--cask', 'x'], ToolManager::BrewCask->installArgs('x'));
        $this->assertSame(['upgrade', '--cask', 'x'], ToolManager::BrewCask->updateArgs('x'));
        $this->assertSame(['install', 'x', '--locked'], ToolManager::Cargo->installArgs('x'));
        $this->assertSame(['install', 'x', '--locked', '--force'], ToolManager::Cargo->updateArgs('x'), 'without --force cargo refuses what is there');
        $this->assertSame(['install', '--global', 'x'], ToolManager::Npm->installArgs('x'));
        $this->assertSame(['install', '--global', 'x@latest'], ToolManager::Npm->updateArgs('x'));
    }

    public function testOnlyRealManagersHaveAnExecutable(): void
    {
        $this->assertSame('composer', ToolManager::Composer->executable());
        $this->assertSame('brew', ToolManager::Brew->executable());
        $this->assertSame('brew', ToolManager::BrewCask->executable());
        $this->assertSame('cargo', ToolManager::Cargo->executable());
        $this->assertSame('npm', ToolManager::Npm->executable());
        $this->assertNull(ToolManager::Github->executable());
        $this->assertNull(ToolManager::Plugin->executable());
        $this->assertSame([], ToolManager::Github->installArgs('o/r'));
        $this->assertSame([], ToolManager::Plugin->updateArgs('install'));
    }
}
