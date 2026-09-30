<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Kernel-less `debug:container` (`composer container`) — the wiring inspector
 * a FrameworkBundle app gets from bin/console, rebuilt on the component API
 * for this kernel-less setup (round 13; the bundle itself was measured and
 * rejected). Builds config/services.php fresh, seeds dummy per-boot env
 * values, and prints one of four views:
 *
 *   (default)        the COMPILED view — every service id that survives
 *                    compile pruning, with its class (what exists at runtime)
 *   --loaded         the pre-compile view — every discovered definition,
 *                    including the private ones the compiler prunes; this
 *                    answers "why did my class not survive?" (compiling strips
 *                    private unreferenced defs and private aliases — the
 *                    ContainerAliasesTest trick)
 *   --tag=<name>     services carrying a tag, with tag attributes (priority,
 *                    key); try porta.ide_service, porta.eager,
 *                    porta.chrome_root, porta.command_provider,
 *                    porta.settings_section, kernel.event_listener
 *   --aliases        interface → id aliases, including the loader-derived
 *                    single-impl set; always the pre-compile view — the
 *                    compiler resolves aliases away (they are all private
 *                    here), so the compiled container simply has none
 */
#[AsCommand(
    name: 'container',
    description: 'Inspect the compiled service container (services, tags, aliases)',
    help: <<<'HELP'
        Kernel-less `debug:container`: builds config/services.php and prints one
        of four views.

          <info>container</info>              compiled services — every id that survives compile pruning
          <info>container --loaded</info>     pre-compile definitions, including the ones the compiler prunes
          <info>container --aliases</info>    interface → id aliases (always pre-compile)
          <info>container --tag=porta.eager</info>  services carrying a tag, with its attributes
        HELP,
    usages: ['--loaded', '--aliases', '--tag=porta.ide_service'],
)]
final class ContainerDebugCommand extends Command
{
    /** The env channel IdeContainerFactory seeds per boot; dummy values suffice — this inspects wiring, it never runs services. */
    private const array ENV_DEFAULTS = [
        'PORTA_LOCALE' => 'en',
        'PORTA_SYSTEM_LOCALE' => 'en',
        'PORTA_ANSI' => '1',
        'PORTA_THEME' => 'dark',
        'PORTA_LSP_COMMAND' => '',
        'PORTA_LSP_DISABLED' => '1',
        'PORTA_DOCS_DIR' => '',
        'PORTA_TRANSLATION_CACHE_DIR' => '',
        'PORTA_SETTINGS' => '{}',
        'PORTA_LANGUAGES' => '{}',
        'PORTA_LSP_SERVERS' => '{}',
        'PORTA_DISABLED_PLUGINS' => '[]',
        'PORTA_ENABLED_PLUGINS' => '[]',
        'PORTA_HIGHLIGHT' => '{}',
    ];

    private readonly string $configDir;

    /** @param string|null $configDir the directory holding services.php; defaults to the package's own */
    public function __construct(?string $configDir = null)
    {
        parent::__construct();
        $this->configDir = $configDir ?? \dirname(__DIR__, levels: 2).'/config';
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('Show the pre-compile definitions instead of the compiled ones', name: 'loaded')]
        // @mago-expect lint:no-boolean-flag-parameter -- Symfony Console's #[Option] shape: a bool option IS a flag.
        bool $loadedView = false,
        #[Option('List interface → id aliases (always pre-compile)', name: 'aliases')]
        // @mago-expect lint:no-boolean-flag-parameter -- Symfony Console's #[Option] shape: a bool option IS a flag.
        bool $aliasView = false,
        #[Option('List the services carrying this tag', name: 'tag')]
        ?string $tagView = null,
    ): int {
        // Non-destructive: only the keys a caller has not already set.
        $_ENV += ['PORTA_ROOT_DIR' => (string) \getcwd()] + self::ENV_DEFAULTS;

        $container = new ContainerBuilder();
        new PhpFileLoader($container, new FileLocator($this->configDir))->load('services.php');
        // Aliases only exist pre-compile (the compiler resolves the private
        // ones away), so --aliases always reads the loaded builder.
        if (!$loadedView && !$aliasView) {
            $container->compile(resolveEnvPlaceholders: true);
        }
        $view = $loadedView || $aliasView ? 'loaded (pre-compile)' : 'compiled (runtime)';
        $io = new SymfonyStyle($input, $output);

        if (null !== $tagView) {
            $this->printTagged($output, $io, $container, $tagView, $view);

            return Command::SUCCESS;
        }
        if ($aliasView) {
            $this->printAliases($output, $io, $container, $view);

            return Command::SUCCESS;
        }
        $this->printServices($output, $io, $container, $view);

        return Command::SUCCESS;
    }

    private function printTagged(OutputInterface $output, SymfonyStyle $io, ContainerBuilder $container, string $tag, string $view): void
    {
        $tagged = $container->findTaggedServiceIds($tag);
        $output->writeln(\sprintf('# %d service(s) tagged %s — %s view', \count($tagged), $tag, $view));
        if ([] === $tagged) {
            return;
        }
        $rows = [];
        foreach ($tagged as $id => $tags) {
            /** @var mixed $attributes */
            foreach ($tags as $attributes) {
                $rows[] = [$id, [] === $attributes ? '' : (string) \json_encode($attributes)];
            }
        }
        $io->table(['Service', 'Tag attributes'], $rows);
    }

    private function printAliases(OutputInterface $output, SymfonyStyle $io, ContainerBuilder $container, string $view): void
    {
        $aliases = $container->getAliases();
        \ksort($aliases);
        $output->writeln(\sprintf('# %d alias(es) — %s view', \count($aliases), $view));
        $rows = [];
        foreach ($aliases as $alias => $target) {
            $rows[] = [$alias, (string) $target];
        }
        $io->table(['Alias', 'Target'], $rows);
    }

    private function printServices(OutputInterface $output, SymfonyStyle $io, ContainerBuilder $container, string $view): void
    {
        $definitions = $container->getDefinitions();
        \ksort($definitions);
        $output->writeln(\sprintf('# %d service(s) — %s view', \count($definitions), $view));
        $rows = [];
        foreach ($definitions as $id => $definition) {
            $class = $definition->getClass() ?? '?';
            $rows[] = [$id, $id === $class ? '' : $class, $this->flags($definition)];
        }
        $io->table(['Service', 'Class', 'Flags'], $rows);
    }

    private function flags(Definition $definition): string
    {
        $flags = [];
        if ($definition->isSynthetic()) {
            $flags[] = 'synthetic';
        }
        if ($definition->isPublic()) {
            $flags[] = 'public';
        }
        if (null !== $definition->getFactory()) {
            $flags[] = 'factory';
        }

        return [] === $flags ? '' : '['.\implode(',', $flags).']';
    }
}
