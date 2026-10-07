<?php

declare(strict_types=1);

namespace Porta\Custos\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Custos\ServerConfig;
use Porta\Plugin\Api\ServerNotFound;

final class ServerConfigTest extends TestCase
{
    public function testTheServerIsTheLspSubcommandAndNeedsABinary(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['/opt/custos', 'lsp'], $config->command('/opt/custos'));
        $this->assertInstanceOf(ServerNotFound::class, $config->command(null), 'no binary: said, not launched');
    }

    public function testNothingIsSentWhileTheProjectConfigDecides(): void
    {
        // An empty `php` sent as an override would replace the project's own
        // target version with nothing; "project" is no style custos knows.
        $this->assertSame([], new ServerConfig()->initializationOptions('', ServerConfig::PROJECT));
        $this->assertSame([], new ServerConfig()->initializationOptions("  \t", ''));
    }

    public function testOnlyTheValuesTheUserChoseTravel(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['php' => '8.3', 'comparisonStyle' => 'yoda'], $config->initializationOptions(' 8.3 ', 'yoda'));
        $this->assertSame(['comparisonStyle' => 'regular'], $config->initializationOptions('', ' regular '));
        $this->assertSame(['php' => '8.1'], $config->initializationOptions('8.1', ServerConfig::PROJECT));
    }

    public function testAStyleCustosDoesNotKnowIsLeftToTheProject(): void
    {
        // A hand-edited .porta.json can hold anything; sent, custos would
        // refuse the whole initialize over it.
        $this->assertSame([], new ServerConfig()->initializationOptions('', 'Yoda!'));
    }

    public function testTheFixReadsTheProjectRootConfigAndTheSameOverrides(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['fix', '--config', '/p', '/p/src/A.php'], $config->fixArguments('/p/src/A.php', '/p', '', ServerConfig::PROJECT));
        $this->assertSame(['fix', '--config', '/p', '--php', '8.2', '/p/A.php'], $config->fixArguments('/p/A.php', '/p', ' 8.2 ', ServerConfig::PROJECT));
        $this->assertSame(['fix', '--config', '/p', '--comparison-style', 'yoda', '/p/A.php'], $config->fixArguments('/p/A.php', '/p', '', 'yoda'));
        $this->assertSame(
            ['fix', '--config', '/p', '--php', '8.3', '--comparison-style', 'regular', '/p/A.php'],
            $config->fixArguments('/p/A.php', '/p', '8.3', 'regular'),
        );
        $this->assertSame(['fix', '--config', '/p', '/p/A.php'], $config->fixArguments('/p/A.php', '/p', '', 'Yoda!'), 'an unknown style is left to the project, as for the server');
    }
}
