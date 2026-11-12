<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Install\IdeUpdateState;
use Porta\Tests\Support\TempProject;

final class IdeUpdateStateTest extends TestCase
{
    public function testCadenceNoteHandlesMissingCorruptAndFutureDates(): void
    {
        $home = new TempProject();
        $state = new IdeUpdateState($home->path('check.json'));
        $this->assertTrue($state->due(100_000, 86_400));
        $state->checked(100_000, 'v1.1.0');
        $this->assertSame('v1.1.0', $state->latest());
        $state->checked(100_000);
        $this->assertSame('v1.1.0', $state->latest());
        $this->assertFalse($state->due(100_001, 86_400));
        $this->assertTrue($state->due(186_400, 86_400));
        $this->assertTrue($state->due(99_999, 86_400));
        $home->write('check.json', '{"checkedAt":"bad"}');
        $this->assertTrue($state->due(100_000, 86_400));
        $disabled = new IdeUpdateState(null);
        $disabled->checked(100_000);
        $this->assertFalse($disabled->due(100_000, 86_400));
    }

    public function testFactoryUsesTheConfiguredHomeAndHandlesNoHome(): void
    {
        $home = new TempProject();
        $before = \getenv();
        try {
            \putenv('PORTA_HOME='.$home->dir);
            IdeUpdateState::forHome()->checked(100_000);
            $this->assertFileExists($home->path('.porta/ide-updates.json'));
            \putenv('PORTA_HOME');
            \putenv('HOME');
            $this->assertFalse(IdeUpdateState::forHome()->due(100_000, 86_400));
        } finally {
            foreach (['HOME', 'PORTA_HOME'] as $key) {
                \putenv(\array_key_exists($key, $before) ? $key.'='.$before[$key] : $key);
            }
        }
    }
}
