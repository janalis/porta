<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\PluginCommands;
use Porta\Plugin\Api\PluginCommand;
use Porta\Tests\Support\TempProject;

/**
 * `porta <name>` for a command a bundled plugin ships: found by name in the
 * manifests, its plugin's classes — and its requirements' — loadable first.
 */
final class PluginCommandsTest extends TestCase
{
    /**
     * Three plugins: `greeter` ships `hello`, whose class extends one from the
     * plugin it requires, `base`; `broken` names a class it does not have, one
     * that is not a command, and a Symfony command that is no PluginCommand. Namespaces carry a per-run token, since the
     * autoloader outlives the test.
     */
    private function plugins(#[\SensitiveParameter] string $token): TempProject
    {
        return new TempProject([
            'base/plugin.php' => "<?php\n",
            'base/composer.json' => \json_encode(['autoload' => ['psr-4' => ["Base{$token}\\" => 'src/']]]),
            'base/src/Greeting.php' => "<?php\nnamespace Base{$token};\nabstract class Greeting extends \\Porta\\Plugin\\Api\\PluginCommand {}\n",
            'greeter/plugin.php' => "<?php\n",
            'greeter/composer.json' => \json_encode([
                'autoload' => ['psr-4' => ["Greeter{$token}\\" => 'src/']],
                'extra' => ['porta' => ['requires' => ['base', 'missing'], 'commands' => ['hello' => "Greeter{$token}\\Hello"]]],
            ]),
            'greeter/src/Hello.php' => "<?php\nnamespace Greeter{$token};\n#[\\Symfony\\Component\\Console\\Attribute\\AsCommand(name: 'hello')]\nfinal class Hello extends \\Base{$token}\\Greeting {}\n",
            'broken/plugin.php' => "<?php\n",
            'broken/composer.json' => \json_encode([
                'autoload' => ['psr-4' => ["Broken{$token}\\" => 'src/']],
                'extra' => ['porta' => ['commands' => ['ghost' => "Broken{$token}\\Nowhere", 'plain' => "Broken{$token}\\Plain", 'bare' => "Broken{$token}\\Bare"]]],
            ]),
            'broken/src/Plain.php' => "<?php\nnamespace Broken{$token};\nfinal class Plain {}\n",
            'broken/src/Bare.php' => "<?php\nnamespace Broken{$token};\n#[\\Symfony\\Component\\Console\\Attribute\\AsCommand(name: 'bare')]\nfinal class Bare extends \\Symfony\\Component\\Console\\Command\\Command {}\n",
        ]);
    }

    public function testACommandIsFoundByNameWithItsRequirementsLoadable(): void
    {
        $token = 'T'.\bin2hex(\random_bytes(4));
        $plugins = $this->plugins($token); // held: a freed TempProject deletes its directory
        $commands = new PluginCommands($plugins->dir);

        $hello = $commands->find('hello');

        $this->assertInstanceOf(PluginCommand::class, $hello);
        $this->assertSame('hello', $hello->getName());
        $this->assertInstanceOf("Base{$token}\\Greeting", $hello, 'the required plugin\'s class resolved too');
    }

    public function testNothingAnswersForNoNameAnUnknownOneOrAClassThatIsNoCommand(): void
    {
        $plugins = $this->plugins('T'.\bin2hex(\random_bytes(4)));
        $commands = new PluginCommands($plugins->dir);

        $this->assertNull($commands->find(''));
        $this->assertNull($commands->find('unknown'));
        $this->assertNull($commands->find('ghost'), 'a class the plugin does not have');
        $this->assertNull($commands->find('plain'), 'a class that is not a command');
        $this->assertNull($commands->find('bare'), 'a Symfony command that is not a PluginCommand');
    }

    public function testTheBundledSetIsThePackagesPlugins(): void
    {
        $saved = \getenv('PORTA_EMBEDDED_PLUGINS');
        \putenv('PORTA_EMBEDDED_PLUGINS='.\dirname(__DIR__, levels: 3).'/plugins');

        try {
            $review = PluginCommands::bundled()->find('review');
        } finally {
            \putenv('PORTA_EMBEDDED_PLUGINS'.(false === $saved ? '' : '='.$saved));
        }

        $this->assertSame('review', $review?->getName(), 'the review plugin ships `porta review`');
    }
}
