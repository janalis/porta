<?php

declare(strict_types=1);

namespace Porta\Run;

use Porta\Support\JsonFile;

/**
 * Discovers runnable tasks for the "Run" menu: the current PHP file, composer
 * scripts (from composer.json), and Makefile targets. Pure functions over the
 * filesystem — no process spawning here.
 */
final readonly class RunConfigs
{
    /**
     * @param array<string, array{runCommand?: string}> $languages per-language overrides from {@see \Porta\Config\IdeConfig::$languages}
     */
    public function __construct(
        private string $rootDir,
        private array $languages = [],
    ) {}

    /**
     * @param string|null $currentFile the file open in the editor, if any
     *
     * @return list<array{label: string, command: list<string>}>
     */
    public function discover(?string $currentFile): array
    {
        $tasks = [];

        if (null !== $currentFile && 'php' === strtolower(pathinfo($currentFile, \PATHINFO_EXTENSION))) {
            $tasks[] = [
                'label' => 'Run current file: '.basename($currentFile),
                'command' => [...$this->phpArgv(), $currentFile],
            ];
        }

        foreach ($this->composerScripts() as $name) {
            $tasks[] = [
                'label' => 'composer '.$name,
                'command' => ['composer', 'run-script', $name],
            ];
        }

        foreach ($this->makeTargets() as $target) {
            $tasks[] = [
                'label' => 'make '.$target,
                'command' => ['make', $target],
            ];
        }

        return $tasks;
    }

    /**
     * The PHP interpreter argv for "Run current file": the configured
     * `languages.php.runCommand` (e.g. `frankenphp php-cli`) split into argv, or
     * the interpreter Porta itself runs under when unset.
     *
     * @return list<string>
     */
    private function phpArgv(): array
    {
        $command = trim($this->languages['php']['runCommand'] ?? '');
        if ('' === $command) {
            return [\PHP_BINARY];
        }
        $parts = preg_split('/\s+/', $command);

        return false === $parts ? [\PHP_BINARY] : $parts;
    }

    /**
     * @return list<string> composer script names, in file order
     */
    public function composerScripts(): array
    {
        $file = $this->rootDir.'/composer.json';
        $json = JsonFile::read($file);
        if (null === $json || !\is_array($json['scripts'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_keys($json['scripts'])),
            static fn (string $name) => '' !== $name,
        ));
    }

    /**
     * @return list<string> Makefile phony/normal target names
     */
    public function makeTargets(): array
    {
        $file = $this->rootDir.'/Makefile';
        if (!is_file($file)) {
            return [];
        }

        $targets = [];
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            // "target: deps" at column 0 — skip pattern rules and variables.
            $m = [];
            if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.-]*)\s*:(?!=)/', $line, $m)) {
                if (!\in_array($m[1], $targets, strict: true)) {
                    $targets[] = $m[1];
                }
            }
        }

        return $targets;
    }
}
