<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\I18n\Translator;
use Porta\Support\AsyncProcess;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs and removes plugin directories under `<project>/.porta/plugins` and
 * `<globalBase>/.porta/plugins`. A source that is an existing directory is
 * copied synchronously; anything else is treated as a git URL and cloned
 * asynchronously on the shared event loop (stderr is discarded by
 * {@see AsyncProcess}, so clone failures can only report the exit code).
 * Activation of the installed plugin is the caller's concern — this class
 * only manages the directories.
 */
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
            $onDone($this->translator->trans('Another install is already running'), null);

            return;
        }
        $base = $this->baseDir($scope);
        if (null === $base) {
            $onDone($this->translator->trans('Cannot install globally (no home directory)'), null);

            return;
        }

        // An explicit URL always clones — is_dir() alone would misroute
        // file:// URLs into the copy branch via PHP's stream wrapper.
        $isUrl = 1 === preg_match('#^(?:[a-z][a-z0-9+.-]*://|git@)#i', $source);
        $isDir = !$isUrl && is_dir($source);
        $name = $isDir ? basename(rtrim($source, '/')) : basename(preg_replace('/\.git$/', '', rtrim($source, '/')) ?? '');
        if (1 !== preg_match(PluginLoader::NAME_PATTERN, $name)) {
            $onDone($this->translator->trans('"%name%" is not a valid plugin name', ['%name%' => $name]), null);

            return;
        }
        $dest = $base.'/'.$name;
        if (is_dir($dest)) {
            $onDone($this->translator->trans('Plugin "%name%" is already installed', ['%name%' => $name]), null);

            return;
        }

        $isDir ? $this->copy($source, $dest, $onDone) : $this->clone($source, $dest, $onDone);
    }

    /** @param \Closure(?string, ?string): void $onDone */
    private function copy(string $source, string $dest, \Closure $onDone): void
    {
        if (!is_file($source.'/plugin.php')) {
            $onDone($this->translator->trans('No plugin.php found in %source%', ['%source%' => $source]), null);

            return;
        }
        $fs = new Filesystem();
        try {
            $fs->mirror($source, $dest);
        } catch (IOExceptionInterface) {
            $fs->remove($dest);
            $onDone($this->translator->trans('Cannot install into %dest%', ['%dest%' => $dest]), null);

            return;
        }
        $onDone(null, $dest.'/plugin.php');
    }

    /** @param \Closure(?string, ?string): void $onDone */
    private function clone(string $source, string $dest, \Closure $onDone): void
    {
        if (null === $this->gitBinary) {
            $onDone($this->translator->trans('git is not available'), null);

            return;
        }
        new Filesystem()->mkdir(\dirname($dest));
        $this->running = new AsyncProcess([$this->gitBinary, 'clone', '--depth', '1', '--', $source, $dest], $this->rootDir);
        $started = $this->running->start(function (int $code) use ($dest, $onDone): void {
            $this->running = null;
            if (0 !== $code) {
                new Filesystem()->remove($dest);
                $onDone($this->translator->trans('git clone failed (exit code %code%)', ['%code%' => $code]), null);

                return;
            }
            if (!is_file($dest.'/plugin.php')) {
                new Filesystem()->remove($dest);
                $onDone($this->translator->trans('The repository contains no plugin.php'), null);

                return;
            }
            $onDone(null, $dest.'/plugin.php');
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onDone($this->translator->trans('git is not available'), null);

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
        $embeddedReal = null !== $this->embeddedDir ? (realpath($this->embeddedDir) ?: null) : null;

        $plugins = [];
        $embeddedSeen = [];
        foreach (['project' => $this->rootDir.'/.porta/plugins', 'global' => null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null] as $scope => $pluginsDir) {
            if (null === $pluginsDir) {
                continue;
            }
            $isEmbedded = null !== $embeddedReal && realpath($pluginsDir) === $embeddedReal;
            foreach (glob($pluginsDir.'/*/plugin.php') ?: [] as $file) {
                $dir = \dirname($file);
                $name = basename($dir);
                $plugins[] = ['name' => $name, 'scope' => $isEmbedded ? 'embedded' : $scope, 'dir' => $dir];
                if ($isEmbedded) {
                    $embeddedSeen[$name] = true;
                }
            }
        }
        if (null !== $this->embeddedDir) {
            foreach (glob($this->embeddedDir.'/*/plugin.php') ?: [] as $file) {
                $dir = \dirname($file);
                $name = basename($dir);
                if (!isset($embeddedSeen[$name])) {
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
