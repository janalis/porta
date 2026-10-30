<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether a project is trusted to run its own code — and, until it is, what of
 * its configuration is not believed.
 *
 * A project is something one clones. Before this, opening one ran it: its
 * `.porta/plugins/*` were `require`d at boot, and its `.porta.json` named the
 * git binary, the language server and every tool's binary, all spawned without
 * a question. An UNTRUSTED project is opened restricted instead: its own
 * plugins are not loaded, the keys of its `.porta.json` that name something to
 * run are dropped ({@see restricted()}), tools are not taken from its
 * `vendor/bin`, and the tools that execute its PHP (a phpstan bootstrap file,
 * a php-cs-fixer config) do not run. Nor does any language server, even one
 * configured globally: a server reads the project's own config and runs what
 * it names (phpantom ran a `.phpantom.toml`'s `[phpstan] command`, and the
 * project's `vendor/bin/phpstan`, in a project opened restricted) — only one
 * a plugin declares run elsewhere, reading nothing of the project. Everything
 * else the user configured globally still applies — trust is about the
 * project's say, not the user's.
 *
 * The user's answers are kept in `<home>/.porta/trusted-projects.json` (0600 —
 * the user's to grow, never a project's): the projects trusted, and the ones
 * opened restricted on purpose, so neither is asked about again. Trusted too is
 * everything under `PORTA_TRUSTED_FOLDERS`: a
 * `:`-separated list of folders whose every project is trusted, for a CI box
 * or a sandbox where asking would only be in the way.
 *
 * The config loader builds its own through {@see fromEnvironment()}, before
 * the container exists; the container builds the services' one the same way.
 */
#[Autoconfigure(constructor: 'fromEnvironment')]
final readonly class WorkspaceTrust
{
    /** What a restricted project's `.porta.json` may not say, at the top level. */
    private const array UNTRUSTED_KEYS = ['lsp', 'enabledPlugins'];

    /**
     * A plugin setting that names a program, a command line, where a token
     * goes, a server to connect to or where files are written — `binary`,
     * `path`, `command`, `dotPath`, `ghBinary`, `svgConverter`, `outputDir`,
     * git's `worktreeFolder`, assist's `mailbox` and `transit`… — or that lets an agent act:
     * mcp's `allowRuns`, `allowFileOps`, `allowEdits`, `allowSave`.
     * Matched by shape, so a third-party plugin naming its own the same way is
     * covered too.
     */
    private const string RUNNABLE_SETTING = '/(?:path|binary|command|converter|dir|folder)$|^allow/i';

    /** Runnable plugin settings whose names do not say so. */
    private const array RUNNABLE_SETTINGS = ['plantuml', 'runtime-indexing', 'args', 'hosts', 'provider', 'mailbox', 'transit'];

    /** @param list<string> $folders trusted with everything beneath them */
    public function __construct(
        private ?string $home,
        private array $folders = [],
        private Filesystem $fs = new Filesystem(),
    ) {}

    /** From the environment, as {@see ConfigFiles::for()} is. */
    public static function fromEnvironment(): self
    {
        $folders = \getenv('PORTA_TRUSTED_FOLDERS');

        return new self(PortaHome::base(), \is_string($folders) ? \array_values(\array_filter(\explode(':', $folders), static fn (string $folder): bool => '' !== $folder)) : []);
    }

    public function isTrusted(string $rootDir): bool
    {
        return $this->trustsFolderOf($rootDir) || \in_array(self::canonical($rootDir), $this->answers()['trusted'], strict: true);
    }

    /** Whether `PORTA_TRUSTED_FOLDERS` trusts $rootDir — which no answer outranks. */
    public function trustsFolderOf(string $rootDir): bool
    {
        $root = self::canonical($rootDir);
        foreach ($this->folders as $folder) {
            $folder = \rtrim(self::canonical($folder), characters: '/');
            if ($root === $folder || \str_starts_with($root, $folder.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether opening $rootDir should ask: never answered either way. Any
     * folder at all, not only one with a `.porta.json` or a `vendor/bin`: a
     * restricted project starts no language server, and a folder holding
     * nothing else can still steer one — a lone `.phpantom.toml` named a
     * program for phpantom to run. Asked once; the answer is kept.
     */
    public function shouldAsk(string $rootDir): bool
    {
        return !$this->isTrusted($rootDir) && !\in_array(self::canonical($rootDir), $this->answers()['restricted'], strict: true);
    }

    /** Trust $rootDir from now on. False when the answer cannot be written. */
    public function trust(string $rootDir): bool
    {
        return $this->answer($rootDir, 'trusted');
    }

    /** Open $rootDir restricted from now on, without asking. False when the answer cannot be written. */
    public function restrict(string $rootDir): bool
    {
        return $this->answer($rootDir, 'restricted');
    }

    /**
     * A restricted project's `.porta.json`, minus everything that names
     * something to run: the language server, which bundled plugins are on, a
     * plugin setting naming a binary or a command, a language's run command
     * and debug adapter, and the pre-plugin core keys that named them.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    public static function restricted(array $config): array
    {
        foreach (self::UNTRUSTED_KEYS as $key) {
            unset($config[$key]);
        }
        $config['plugins'] = \array_map(
            static fn (mixed $settings): mixed => \is_array($settings) ? self::withoutRunnable($settings) : $settings,
            \is_array($config['plugins'] ?? null) ? $config['plugins'] : [],
        );
        $config['languages'] = \array_map(
            static fn (mixed $settings): mixed => \is_array($settings) ? \array_diff_key($settings, ['runCommand' => true, 'debug' => true]) : $settings,
            \is_array($config['languages'] ?? null) ? $config['languages'] : [],
        );
        if (\is_array($config['settings'] ?? null)) {
            $config['settings'] = self::withoutRunnable($config['settings']);
        }

        return $config;
    }

    /**
     * @param array<array-key, mixed> $settings
     *
     * @return array<array-key, mixed>
     */
    private static function withoutRunnable(array $settings): array
    {
        return \array_filter(
            $settings,
            static fn (int|string $key): bool => 1 !== \preg_match(self::RUNNABLE_SETTING, (string) $key) && !\in_array($key, self::RUNNABLE_SETTINGS, strict: true),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param 'trusted'|'restricted' $as */
    private function answer(string $rootDir, string $as): bool
    {
        $root = self::canonical($rootDir);
        $answers = $this->answers();
        foreach ($answers as $list => $roots) {
            $answers[$list] = \array_values(\array_filter($roots, static fn (string $answered): bool => $answered !== $root));
        }
        $answers[$as][] = $root;
        $file = $this->file();
        if (null === $file || !JsonFile::writeEditable($this->fs, $file, $answers)) {
            return false;
        }
        $this->fs->chmod($file, mode: 0o600);

        return true;
    }

    /**
     * Each project the user answered for, by answer.
     *
     * @return array{trusted: list<string>, restricted: list<string>}
     */
    private function answers(): array
    {
        $file = $this->file();
        $data = null === $file ? null : JsonFile::read($file);
        // @noinspection UnqualifiedReferenceInspection -- mago narrows the filtered list to strings through the unqualified callable, not the qualified one
        $list = static fn (mixed $roots): array => \is_array($roots) ? \array_values(\array_filter($roots, is_string(...))) : [];

        return ['trusted' => $list($data['trusted'] ?? null), 'restricted' => $list($data['restricted'] ?? null)];
    }

    private function file(): ?string
    {
        return null === $this->home ? null : $this->home.'/.porta/trusted-projects.json';
    }

    private static function canonical(string $path): string
    {
        $real = \realpath($path);

        return false === $real ? \rtrim($path, characters: '/') : $real;
    }
}
