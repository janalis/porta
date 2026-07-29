<?php

declare(strict_types=1);

/**
 * Kernel-less debug:container (`composer container`) — the wiring inspector a
 * FrameworkBundle app gets from bin/console, rebuilt on the component API for
 * this kernel-less setup (round 13; the bundle itself was measured and
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

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

require __DIR__.'/../vendor/autoload.php';

// The env channel IdeContainerFactory seeds per boot; dummy values suffice —
// the tool inspects wiring, it never runs services.
$_ENV += [
    'PORTA_ROOT_DIR' => false === ($cwd = getcwd()) ? '/tmp' : $cwd,
    'PORTA_LOCALE' => 'en',
    'PORTA_ANSI' => '1',
    'PORTA_THEME' => 'dark',
    'PORTA_LSP_COMMAND' => '',
    'PORTA_LSP_DISABLED' => '1',
    'PORTA_DOCS_DIR' => '',
    'PORTA_GIT_PATH' => '',
    'PORTA_SETTINGS' => '{}',
    'PORTA_LANGUAGES' => '{}',
    'PORTA_LSP_SERVERS' => '{}',
    'PORTA_DISABLED_PLUGINS' => '[]',
    'PORTA_ENABLED_PLUGINS' => '[]',
    'PORTA_HIGHLIGHT' => '{}',
];

$args = array_slice($argv, offset: 1);
$loadedView = in_array('--loaded', $args, strict: true);
$aliasView = in_array('--aliases', $args, strict: true);
$tagView = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--tag=')) {
        $tagView = substr($arg, offset: 6);
        continue;
    }
    if (in_array($arg, ['--loaded', '--aliases'], strict: true)) {
        continue;
    }
    $usage = 'usage: composer container [-- --loaded] [-- --tag=<name>] [-- --aliases]';
    fwrite(STDERR, "container-debug: unknown option {$arg}\n{$usage}\n");
    exit(2);
}

$container = new ContainerBuilder();
new PhpFileLoader($container, new FileLocator(__DIR__.'/../config'))->load('services.php');
// Aliases only exist pre-compile (the compiler resolves the private ones away),
// so --aliases always reads the loaded builder.
if (!$loadedView && !$aliasView) {
    $container->compile(resolveEnvPlaceholders: true);
}
$view = $loadedView || $aliasView ? 'loaded (pre-compile)' : 'compiled (runtime)';

if (null !== $tagView) {
    $tagged = $container->findTaggedServiceIds($tagView);
    printf("# %d service(s) tagged %s — %s view\n", count($tagged), $tagView, $view);
    foreach ($tagged as $id => $tags) {
        foreach ($tags as $attributes) {
            $extra = [] === $attributes ? '' : ' '.json_encode($attributes);
            printf("%s%s\n", $id, $extra);
        }
    }
    exit(0);
}

if ($aliasView) {
    $aliases = $container->getAliases();
    ksort($aliases);
    printf("# %d alias(es) — %s view\n", count($aliases), $view);
    foreach ($aliases as $alias => $target) {
        printf("%s -> %s\n", $alias, (string) $target);
    }
    exit(0);
}

$definitions = $container->getDefinitions();
ksort($definitions);
printf("# %d service(s) — %s view\n", count($definitions), $view);
foreach ($definitions as $id => $definition) {
    $class = $definition->getClass() ?? '?';
    $flags = [];
    if ($definition->isSynthetic()) {
        $flags[] = 'synthetic';
    }
    if ($definition->isPublic()) {
        $flags[] = 'public';
    }
    if ([] !== $definition->getFactory() && null !== $definition->getFactory()) {
        $flags[] = 'factory';
    }
    $suffix = [] === $flags ? '' : ' ['.implode(',', $flags).']';
    $shownClass = $id === $class ? '' : " ({$class})";
    printf("%s%s%s\n", $id, $shownClass, $suffix);
}
