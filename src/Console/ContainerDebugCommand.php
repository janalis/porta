<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
#[AsCommand(name: 'container', description: 'Inspect the compiled service container (services, tags, aliases)')]
final class ContainerDebugCommand extends Command
{
    /** The env channel IdeContainerFactory seeds per boot; dummy values suffice — this inspects wiring, it never runs services. */
    private const array ENV_DEFAULTS = [
        'PORTA_LOCALE' => 'en',
        'PORTA_ANSI' => '1',
        'PORTA_THEME' => 'dark',
        'PORTA_LSP_COMMAND' => '',
        'PORTA_LSP_DISABLED' => '1',
        'PORTA_DOCS_DIR' => '',
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
        OutputInterface $output,
        #[Option('Show the pre-compile definitions instead of the compiled ones', name: 'loaded')]
        bool $loadedView = false,
        #[Option('List interface → id aliases (always pre-compile)', name: 'aliases')]
        bool $aliasView = false,
        #[Option('List the services carrying this tag', name: 'tag')]
        ?string $tagView = null,
    ): int {
        // Non-destructive: only the keys a caller has not already set.
        $_ENV += ['PORTA_ROOT_DIR' => (string) getcwd()] + self::ENV_DEFAULTS;

        $container = new ContainerBuilder();
        new PhpFileLoader($container, new FileLocator($this->configDir))->load('services.php');
        // Aliases only exist pre-compile (the compiler resolves the private
        // ones away), so --aliases always reads the loaded builder.
        if (!$loadedView && !$aliasView) {
            $container->compile(resolveEnvPlaceholders: true);
        }
        $view = $loadedView || $aliasView ? 'loaded (pre-compile)' : 'compiled (runtime)';

        if (null !== $tagView) {
            $this->printTagged($output, $container, $tagView, $view);

            return Command::SUCCESS;
        }
        if ($aliasView) {
            $this->printAliases($output, $container, $view);

            return Command::SUCCESS;
        }
        $this->printServices($output, $container, $view);

        return Command::SUCCESS;
    }

    private function printTagged(OutputInterface $output, ContainerBuilder $container, string $tag, string $view): void
    {
        $tagged = $container->findTaggedServiceIds($tag);
        $output->writeln(\sprintf('# %d service(s) tagged %s — %s view', \count($tagged), $tag, $view));
        foreach ($tagged as $id => $tags) {
            /** @var mixed $attributes */
            foreach ($tags as $attributes) {
                $extra = [] === $attributes ? '' : ' '.(string) json_encode($attributes);
                $output->writeln($id.$extra);
            }
        }
    }

    private function printAliases(OutputInterface $output, ContainerBuilder $container, string $view): void
    {
        $aliases = $container->getAliases();
        ksort($aliases);
        $output->writeln(\sprintf('# %d alias(es) — %s view', \count($aliases), $view));
        foreach ($aliases as $alias => $target) {
            $output->writeln($alias.' -> '.(string) $target);
        }
    }

    private function printServices(OutputInterface $output, ContainerBuilder $container, string $view): void
    {
        $definitions = $container->getDefinitions();
        ksort($definitions);
        $output->writeln(\sprintf('# %d service(s) — %s view', \count($definitions), $view));
        foreach ($definitions as $id => $definition) {
            $class = $definition->getClass() ?? '?';
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
            $suffix = [] === $flags ? '' : ' ['.implode(',', $flags).']';
            $shownClass = $id === $class ? '' : " ({$class})";
            $output->writeln($id.$shownClass.$suffix);
        }
    }
}
