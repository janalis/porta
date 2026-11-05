<?php

declare(strict_types=1);

namespace Porta\Xdebug;

/**
 * Where the vscode-php-debug adapter is: the script `node` runs.
 *
 * Looked for in one order: the path the user set, then the copy this plugin
 * installed, then the php-debug extension an editor of the VS Code family
 * already installed — the newest of those. Reusing one costs no download and
 * is the copy the user keeps up to date.
 */
final readonly class AdapterLocator
{
    /** The release the Install button fetches, and the SHA-256 it must have. */
    public const string VERSION = '1.40.2';
    public const string SHA256 = '17631993fe800083a2fc89a6782c8bf58a79603ad201ddff60d9b59ba2345d5f';
    public const string URL = 'https://github.com/xdebug/vscode-php-debug/releases/download/v'.self::VERSION.'/php-debug-'.self::VERSION.'.vsix';

    /** Where the editors of the VS Code family keep their extensions, under home. */
    private const array EDITORS = ['.vscode', '.vscode-insiders', '.cursor', '.vscode-oss', '.windsurf'];

    private const string SCRIPT = '/out/phpDebug.js';

    /**
     * @param string|null $home    the user's home, null when there is none
     * @param string      $setting the path the user set, '' when none
     */
    public function __construct(
        private ?string $home,
        private string $setting,
    ) {}

    /** Where the Install button puts the adapter: one directory per pinned version. */
    public function installDir(): ?string
    {
        return null === $this->home ? null : $this->home.'/.porta/tools/php-debug/'.self::VERSION;
    }

    /** The adapter's script, or null when there is none. */
    public function script(): ?string
    {
        if ('' !== $this->setting) {
            return \is_file($this->setting) ? $this->setting : null;
        }
        $installed = $this->installed();

        return $installed ?? $this->editorCopy();
    }

    /** The copy the Install button put there, or null. */
    public function installed(): ?string
    {
        $dir = $this->installDir();

        return null !== $dir && \is_file($dir.'/extension'.self::SCRIPT) ? $dir.'/extension'.self::SCRIPT : null;
    }

    /** What is in use, in a few words for the Settings page: its version, and where it came from. */
    public function describe(): string
    {
        $script = $this->script();

        $version = null === $script ? null : self::versionOf($script);

        return match (true) {
            null === $script => '',
            $script === $this->installed() => self::VERSION,
            null !== $version => $version.' ('.\basename(\dirname($script, levels: 4)).')',
            default => $script,
        };
    }

    /** The version an editor's php-debug directory names, or null for a script outside one. */
    private static function versionOf(string $script): ?string
    {
        $match = [];

        return 1 === \preg_match('~/xdebug\.php-debug-([\d.]+)[^/]*/~', $script, $match) ? $match[1] : null;
    }

    /** The newest php-debug an editor installed, or null. */
    private function editorCopy(): ?string
    {
        if (null === $this->home) {
            return null;
        }
        $found = [];
        foreach (self::EDITORS as $editor) {
            $scripts = \glob($this->home.'/'.$editor.'/extensions/xdebug.php-debug-*'.self::SCRIPT);
            foreach (false === $scripts ? [] : $scripts as $script) {
                $found[(string) self::versionOf($script)] = $script;
            }
        }
        \uksort($found, static fn (string $a, string $b): int => (int) \version_compare($a, $b));

        return [] === $found ? null : \end($found);
    }
}
