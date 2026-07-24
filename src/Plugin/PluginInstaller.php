<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\I18n\Translator;
use Porta\Support\AsyncProcess;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs and removes plugin directories under `<project>/.porta/plugins` and
 * `<globalBase>/.porta/plugins`. A source that is an existing directory is
 * copied synchronously; anything else is treated as a git URL and cloned
 * asynchronously on the shared event loop (stderr is discarded by
 * {@see AsyncProcess}, so clone failures can only report the exit code).
 * Activation of the installed plugin is the caller's concern — this class
 * only manages the directories.
 */
#[Autoconfigure(constructor: 'create')]
final class PluginInstaller
{
    /** Single-flight: the clone in progress, if any. */
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $globalBase,
        private readonly Translator $translator,
        private readonly ?string $gitBinary,
        private readonly ?string $embeddedDir = null,
    ) {}

    /** The production wiring: plugin dirs and the git binary resolved from the environment. */
    public static function create(string $rootDir, Translator $translator): self
    {
        return new self($rootDir, PluginLoader::globalBase(), $translator, new ExecutableFinder()->find('git'), PluginLoader::embeddedDir());
    }

    /**
     * Install from a directory path (copy) or git URL (clone) into the scope's
     * plugin dir. Exactly one $onDone call: a translated error, or the
     * installed plugin.php path.
     *
     * @param \Closure(?string, ?string): void $onDone (?error, ?pluginFile)
     */
    public function install(string $source, string $scope, \Closure $onDone): void
    {
        if (null !== $this->running) {
            $this->fail($onDone, $this->translator->trans('Another install is already running'));

            return;
        }
        $base = $this->baseDir($scope);
        if (null === $base) {
            $this->fail($onDone, $this->translator->trans('Cannot install globally (no home directory)'));

            return;
        }

        // An explicit URL always clones — is_dir() alone would misroute
        // file:// URLs into the copy branch via PHP's stream wrapper.
        $isUrl = 1 === preg_match('#^(?:[a-z][a-z0-9+.-]*://|git@)#i', $source);
        $isDir = !$isUrl && is_dir($source);
        $name = $isDir ? basename(rtrim($source, characters: '/')) : basename(preg_replace('/\.git$/', replacement: '', subject: rtrim($source, characters: '/')) ?? '');
        if (1 !== preg_match(PluginLoader::NAME_PATTERN, $name)) {
            $this->fail($onDone, $this->translator->trans('"%name%" is not a valid plugin name', ['%name%' => $name]));

            return;
        }
        $dest = $base.'/'.$name;
        if (is_dir($dest)) {
            $this->fail($onDone, $this->translator->trans('Plugin "%name%" is already installed', ['%name%' => $name]));

            return;
        }

        $isDir ? $this->copy($source, $dest, $onDone) : $this->clone($source, $dest, $onDone);
    }

    /**
     * Report a failed install: invoke $onDone with the error message and no
     * plugin file. Extracted so the null second argument stays a variable
     * (named arguments are not allowed on closure calls).
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function fail(\Closure $onDone, string $message): void
    {
        $noFile = null;
        $onDone($message, $noFile);
    }

    /** @param \Closure(?string, ?string): void $onDone */
    private function copy(string $source, string $dest, \Closure $onDone): void
    {
        if (!is_file($source.'/plugin.php')) {
            $this->fail($onDone, $this->translator->trans('No plugin.php found in %source%', ['%source%' => $source]));

            return;
        }
        $fs = new Filesystem();
        try {
            $fs->mirror($source, $dest);
        } catch (IOExceptionInterface) {
            $fs->remove($dest);
            $this->fail($onDone, $this->translator->trans('Cannot install into %dest%', ['%dest%' => $dest]));

            return;
        }
        $onDone(null, $dest.'/plugin.php');
    }

    /** @param \Closure(?string, ?string): void $onDone */
    private function clone(string $source, string $dest, \Closure $onDone): void
    {
        if (null === $this->gitBinary) {
            $this->fail($onDone, $this->translator->trans('git is not available'));

            return;
        }
        new Filesystem()->mkdir(\dirname($dest));
        $this->running = new AsyncProcess([$this->gitBinary, 'clone', '--depth', '1', '--', $source, $dest], $this->rootDir);
        $started = $this->running->start(function (int $code) use ($dest, $onDone): void {
            $this->running = null;
            if (0 !== $code) {
                new Filesystem()->remove($dest);
                $this->fail($onDone, $this->translator->trans('git clone failed (exit code %code%)', ['%code%' => $code]));

                return;
            }
            if (!is_file($dest.'/plugin.php')) {
                new Filesystem()->remove($dest);
                $this->fail($onDone, $this->translator->trans('The repository contains no plugin.php'));

                return;
            }
            $onDone(null, $dest.'/plugin.php');
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;
            $this->fail($onDone, $this->translator->trans('git is not available'));

            // @codeCoverageIgnoreEnd
        }
    }

    /** Delete an installed plugin's directory; a translated error, or null on success. */
    public function remove(string $name, string $scope): ?string
    {
        if ('embedded' === $scope) {
            return $this->translator->trans('Built-in plugins cannot be removed');
        }
        $base = $this->baseDir($scope);
        try {
            if (null === $base) {
                throw new IOException('no global base');
            }
            new Filesystem()->remove($base.'/'.$name);
        } catch (IOExceptionInterface) {
            return $this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]);
        }

        return null;
    }

    /**
     * Every installed plugin directory in precedence order — project, global,
     * then the plugins bundled with Porta (scope 'embedded', never removable).
     * A name present in several scopes is listed once per copy (the loader's
     * shadowing only affects which one activates). In a Porta dev checkout the
     * project plugins dir IS the embedded dir — those entries classify as
     * embedded so the bundled plugins stay non-removable there too.
     *
     * @return list<array{name: string, scope: string, dir: string}>
     */
    public function installed(): array
    {
        $embeddedReal = null;
        if (null !== $this->embeddedDir) {
            $resolvedEmbedded = realpath($this->embeddedDir);
            $embeddedReal = $resolvedEmbedded ? $resolvedEmbedded : null;
        }

        $plugins = [];
        $embeddedSeen = [];
        foreach (['project' => $this->rootDir.'/.porta/plugins', 'global' => null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null] as $scope => $pluginsDir) {
            if (null === $pluginsDir) {
                continue;
            }
            $isEmbedded = null !== $embeddedReal && realpath($pluginsDir) === $embeddedReal;
            $scopeFiles = glob($pluginsDir.'/*/plugin.php');
            foreach (\is_array($scopeFiles) ? $scopeFiles : [] as $file) {
                $dir = \dirname($file);
                $name = basename($dir);
                $plugins[] = ['name' => $name, 'scope' => $isEmbedded ? 'embedded' : $scope, 'dir' => $dir];
                if ($isEmbedded) {
                    $embeddedSeen[$name] = true;
                }
            }
        }
        if (null !== $this->embeddedDir) {
            $embeddedFiles = glob($this->embeddedDir.'/*/plugin.php');
            foreach (\is_array($embeddedFiles) ? $embeddedFiles : [] as $file) {
                $dir = \dirname($file);
                $name = basename($dir);
                if (!array_key_exists($name, $embeddedSeen)) {
                    $plugins[] = ['name' => $name, 'scope' => 'embedded', 'dir' => $dir];
                }
            }
        }

        return $plugins;
    }

    /** The scope's plugin base dir, or null when the global base is unknown. */
    private function baseDir(string $scope): ?string
    {
        $root = 'global' === $scope ? $this->globalBase : $this->rootDir;

        return null === $root ? null : $root.'/.porta/plugins';
    }
}
