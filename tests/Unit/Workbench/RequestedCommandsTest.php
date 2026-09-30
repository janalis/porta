<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Event\CommandRunRequested;
use Porta\I18n\Translator;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\RequestedCommands;

/** A command asked for by id — by a plugin reaching another's verb — runs, or the user hears why not. */
final class RequestedCommandsTest extends TestCase
{
    use StatusReads;

    public function testTheCommandRunsOrTheUserIsToldNothingAnswers(): void
    {
        $ran = [];
        $commands = new CommandRegistry();
        $commands->add('local-history:recent_changes', static function () use (&$ran): void {
            $ran[] = 'recent';
        });
        $status = new StatusBarWidget();
        $requested = new RequestedCommands($commands, new Notifier($status, TestTui::create()), new Translator('en'));

        $requested->onCommandRunRequested(new CommandRunRequested('local-history:recent_changes'));
        $this->assertSame(['recent'], $ran);
        $this->assertSame('', $this->flashText($status), 'nothing to say when it ran');

        $requested->onCommandRunRequested(new CommandRunRequested('gone:verb'));
        $this->assertSame(['recent'], $ran);
        $this->assertStringContainsString('Nothing answers to gone:verb — is its plugin enabled?', $this->flashText($status));
    }
}
