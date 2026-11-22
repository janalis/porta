<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use Janalis\Tuink\Driver\InProcessDriver;
use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Behat\PortaApplication;
use Porta\Tests\Support\Services;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The project a scenario opens, and the steps that reach into the running Ide
 * for what the screen cannot show.
 *
 * Every helper here is public so the other Porta contexts share one way in.
 */
final class PortaContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    private const string PROJECT_CONFIG = '.porta.json';

    // --- The project ------------------------------------------------------------

    #[Given('a PHP project')]
    public function aPhpProject(): void
    {
        $this->getSession()->getWorkspace()->write('composer.json', "{}\n");
    }

    #[Given('the project configuration:')]
    public function theProjectConfiguration(PyStringNode $json): void
    {
        $this->mergeProjectConfig(self::decode($json->getRaw()));
    }

    #[Given('the bundled plugin :names is enabled')]
    #[Given('the bundled plugins :names are enabled')]
    public function theBundledPluginsAreEnabled(string $names): void
    {
        // In .porta.json, which both drivers read whatever arguments the start step passes. An explicit
        // list REPLACES the defaults, so it starts from them: the scenario adds a plugin, it does not
        // turn the default ones off.
        $this->useBundledPlugins();
        $config = $this->projectConfig();
        $enabled = \is_array($config['enabledPlugins'] ?? null) ? $config['enabledPlugins'] : IdeConfig::DEFAULT_ENABLED_PLUGINS;
        $plugins = \array_map(\trim(...), \explode(',', $names));
        $config['enabledPlugins'] = \array_values(\array_unique([...$enabled, ...$plugins]));
        $this->getSession()->getWorkspace()->write(self::PROJECT_CONFIG, self::encode($config));
    }

    #[Given('the bundled plugins are available')]
    public function useBundledPlugins(): void
    {
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('PORTA_EMBEDDED_PLUGINS', \dirname(__DIR__, 3).'/plugins'));
    }

    #[Given('it is the first run in this project')]
    public function itIsTheFirstRun(): void
    {
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('PORTA_BEHAT_FIRST_RUN', '1'));
    }

    /**
     * The in-process factory does this itself; the real entry point shows the Welcome on a first run.
     */
    #[Given('the Welcome was already seen')]
    public function theWelcomeWasAlreadySeen(): void
    {
        new OnboardingState($this->getSession()->getWorkspace()->path)->markWelcomeSeen();
    }

    /**
     * One of the PHPUnit rig's fixture servers, `tests/Support/<name>-lsp-server.php`:
     * `lens` lenses, `labelled` names its completions after itself.
     */
    #[Given('the :name fixture language server is configured')]
    public function theFixtureLanguageServer(string $name): void
    {
        $this->mergeProjectConfig(['lsp' => \PHP_BINARY.' '.\dirname(__DIR__, 2).'/Support/'.$name.'-lsp-server.php']);
    }

    #[Given('the mock language server is configured')]
    public function theMockLanguageServer(): void
    {
        $this->mergeProjectConfig(['lsp' => \PHP_BINARY.' '.\dirname(__DIR__, 2).'/Support/mock-lsp-server.php']);
    }

    #[Given('a git repository with everything committed')]
    public function aGitRepositoryWithEverythingCommitted(): void
    {
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'initial');
    }

    /** A repository of its own under the workspace: a plugin to install from a git URL, a remote. */
    #[Given('the folder :folder is a git repository with everything committed')]
    public function theFolderIsAGitRepository(string $folder): void
    {
        $this->git('-C', $folder, 'init', '-q', '-b', 'main');
        $this->git('-C', $folder, 'add', '-A');
        $this->git('-C', $folder, 'commit', '-q', '-m', 'initial');
    }

    #[Given('I commit everything as :message')]
    public function iCommitEverything(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    /**
     * Runs git in the workspace with an identity of its own and no user or system config.
     */
    public function git(string ...$arguments): string
    {
        $root = $this->getSession()->getWorkspace()->path;
        $git = new Process(
            ['git', '--no-optional-locks', '-c', 'user.name=Behat', '-c', 'user.email=behat@example.invalid', '-c', 'commit.gpgsign=false', ...$arguments],
            $root,
            ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $root],
        );

        return $git->mustRun()->getOutput();
    }

    // --- Actions ----------------------------------------------------------------

    /**
     * The way a user reaches an action: its key, where the focused widget or
     * the IDE binds one, else Find Action by its label.
     */
    #[When('I use the action :id')]
    public function iUseTheAction(string $id): void
    {
        $session = $this->getSession();
        $key = $this->keyFor($id);
        if (null !== $key) {
            $session->press($key);

            return;
        }

        $this->iFindTheAction($this->labelFor($id));
        $session->press('Enter');
    }

    #[When('I find the action :label')]
    public function iFindTheAction(string $label): void
    {
        $session = $this->getSession();
        $session->press($this->keyFor('ide_command_palette') ?? 'ctrl+shift+a');
        $session->waitUntil(static fn (): bool => \str_contains($session->getScreenText(), 'Find Action'), 'Find Action did not open.');
        $session->type($label);
    }

    /**
     * The first key bound to $id, in the focused widget's keymap or the IDE's.
     */
    public function keyFor(string $id): ?string
    {
        $focus = $this->tui()->getFocus();
        if ($focus instanceof FocusableInterface) {
            $keys = $focus->getKeybindings()->getBindings($id);
            if ([] !== $keys) {
                return $keys[0];
            }
        }

        return $this->service(Keybindings::class)->getBindings($id)[0] ?? null;
    }

    public function labelFor(string $id): string
    {
        $label = ActionCatalog::ACTIONS[$id]['label'] ?? $this->service(CommandRegistry::class)->label($id);
        if (null === $label) {
            throw new \InvalidArgumentException(\sprintf('No action or command "%s".', $id));
        }

        return $label;
    }

    // --- The editor -------------------------------------------------------------

    #[Then('the editor should contain :text')]
    public function theEditorShouldContain(string $text): void
    {
        $this->waitForEditor(static fn (string $doc): bool => \str_contains($doc, $text), \sprintf('The editor does not contain "%s".', $text));
    }

    #[Then('the editor should not contain :text')]
    public function theEditorShouldNotContain(string $text): void
    {
        $this->waitForEditor(static fn (string $doc): bool => !\str_contains($doc, $text), \sprintf('The editor still contains "%s".', $text));
    }

    #[Then('the editor text should be:')]
    public function theEditorTextShouldBe(PyStringNode $text): void
    {
        $expected = $text->getRaw();
        $this->waitForEditor(
            static fn (string $doc): bool => \rtrim($doc, "\n") === \rtrim($expected, "\n"),
            "The editor text differs from:\n".$expected,
        );
    }

    #[Then('the editor should show :file')]
    public function theEditorShouldShow(string $file): void
    {
        $session = $this->getSession();
        $path = $session->getWorkspace()->resolve($file);
        $session->waitUntil(
            // realpath() is false for a path that does not exist: two of those are not the same file
            fn (): bool => false !== \realpath($path) && \realpath($path) === \realpath((string) $this->editor()?->getFilePath()),
            \sprintf('The editor does not show "%s".', $file),
        );
    }

    /**
     * The editor's text, or null while no editor is mounted (a viewer has the pane): the checks retry.
     */
    public function editorText(): ?string
    {
        return $this->editor()?->getDocument()->getText();
    }

    /**
     * The editor widget, or null while none is mounted.
     */
    public function editor(): ?CodeEditorWidget
    {
        try {
            $editor = $this->tui()->getById('editor');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $editor instanceof CodeEditorWidget ? $editor : null;
    }

    /**
     * @param \Closure(string): bool $holds
     */
    private function waitForEditor(\Closure $holds, string $message): void
    {
        $this->getSession()->waitUntil(
            function () use ($holds): bool {
                $text = $this->editorText();

                return null !== $text && $holds($text);
            },
            fn (): string => $message."\n\nThe editor holds:\n".($this->editorText() ?? '(no editor is mounted)'),
        );
    }

    // --- The running Ide --------------------------------------------------------

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function service(string $class): object
    {
        return Services::resolve($this->ide(), $class);
    }

    public function tui(): Tui
    {
        return $this->service(Tui::class);
    }

    public function ide(): Ide
    {
        $driver = $this->getSession()->getDriver();
        if (!$driver instanceof InProcessDriver) {
            throw new \LogicException('This step reads the Ide itself, which only the in-process driver can.');
        }
        $application = $driver->getApplication();
        if (!$application instanceof PortaApplication) {
            throw new \LogicException('The in-process factory did not build a Porta application.');
        }

        return $application->ide;
    }

    // --- .porta.json --------------------------------------------------------------

    /**
     * @return array<array-key, mixed>
     */
    public function projectConfig(): array
    {
        $raw = $this->getSession()->getWorkspace()->read(self::PROJECT_CONFIG);

        return null === $raw ? [] : self::decode($raw);
    }

    /**
     * @param array<array-key, mixed> $values merged recursively over what the file holds
     */
    public function mergeProjectConfig(array $values): void
    {
        $this
            ->getSession()
            ->getWorkspace()
            ->write(self::PROJECT_CONFIG, self::encode(self::merge($this->projectConfig(), $values)));
    }

    /**
     * $over merged into $base: objects key by key, recursively; a list REPLACES the one it lands on, as an
     * explicit `enabledPlugins` replaces the defaults (array_replace_recursive would overwrite it slot by slot).
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $over
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach (\array_keys($over) as $key) {
            $base[$key] = self::isObject($base[$key] ?? null) && self::isObject($over[$key])
                ? self::merge(self::object($base[$key]), self::object($over[$key]))
                : $over[$key];
        }

        return $base;
    }

    /** Whether a decoded JSON value is an object, which merges, rather than a list or a scalar, which replaces. */
    private static function isObject(mixed $value): bool
    {
        return \is_array($value) && !\array_is_list($value);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function encode(array $config): string
    {
        return \json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        return self::object(\json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function object(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('The configuration must be a JSON object.');
        }

        return $decoded;
    }
}
