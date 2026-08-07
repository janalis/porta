<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The architecture gate: the structural facts the six-round SOLID audit
 * established (July 2026), enforced as a ratchet. Three checks:
 *
 *   1. Namespace edges — the exact import graph between top-level src/
 *      namespaces, pinned below. A NEW edge fails (new coupling: justify it
 *      by adding it here in the same PR, where it is reviewable); a listed
 *      edge that no longer exists ALSO fails (tighten the list — a stale
 *      allowlist is not a gate, the mago.toml doctrine).
 *   2. Class-level acyclicity — no multi-member strongly-connected component
 *      may exist except the pinned ones below. Docblocks and comments are
 *      stripped before an import counts as used, so {@see}-only imports
 *      (the SpanSlicer/FloatingRegions prose references) form no edge.
 *   3. E2e suite size — no tests/E2e file may exceed E2E_MAX_TESTS methods.
 *      The retired IdeEdgeCasesTest monolith had 187 and pinned two lint
 *      ratchets; the largest suite today has 42.
 *
 * Runs from the dev console (`bin/porta-dev`, via `composer arch`): a
 * maintainer gate over Porta\'s own tree, which is why the vendor prefix and
 * the pins are baked in. The scan root is an argument and the pins are
 * constructor-injectable so the checker can be driven over fixture trees —
 * which is also how it earns its own 100% coverage. It scans src/, so it
 * scans itself: keep it importing nothing from `Porta\` (its pin strings are
 * invisible to the `use Porta\…;` regex) or it grows the very edges it
 * grades.
 */
#[AsCommand(name: 'arch', description: "Enforce Porta's namespace, acyclicity and E2e-size ratchets")]
final class ArchitectureCheckCommand extends Command
{
    /** The audited namespace import graph, sorted, as 'From>To'. */
    private const array ALLOWED_EDGES = [
        'Bootstrap>Config',
        'Bootstrap>Container',
        'Bootstrap>Editor',
        'Bootstrap>Help',
        'Bootstrap>Overlay',
        'Bootstrap>Plugin',
        'Bootstrap>Run',
        'Bootstrap>Widget',
        'Bootstrap>Workbench',
        'Command>Config',
        'Command>Container',
        'Command>Debug',
        'Command>Editor',
        'Command>Git',
        'Command>Layout',
        'Command>Lsp',
        'Command>Plugin',
        'Command>Run',
        'Command>Search',
        'Command>Settings',
        'Command>Widget',
        'Command>Workbench',
        'Config>Container',
        'Config>Editor',
        'Config>Plugin',
        'Config>Support',
        'Console>Container',
        'Container>Config',
        'Debug>Container',
        'Debug>Editor',
        'Debug>Event',
        'Debug>Support',
        'Debug>Terminal',
        'Debug>Ui',
        'Debug>Widget',
        'Debug>Workbench',
        'Editor>Config',
        'Editor>Container',
        'Editor>Event',
        'Editor>Highlight',
        'Editor>Lsp',
        'Editor>Search',
        'Editor>Support',
        'Editor>Ui',
        'Editor>Widget',
        'Editor>Workbench',
        'Event>Editor',
        'Event>Git',
        'Event>Workbench',
        'Event>Widget',
        'Git>Container',
        'Git>Event',
        'Git>Layout',
        'Git>Support',
        'Git>Ui',
        'Git>Widget',
        'Git>Workbench',
        // The graph-viewer plugin's own classes (plugins/graph-viewer/src): the
        // architecture scanner reaches core's AsyncProcess and JsonFile. A
        // plugin depending on core is the allowed direction — core depending on
        // a plugin is what the missing `Editor>GraphViewer` edge shows it does
        // not do.
        // The graph viewer's own code (plugins/graph-viewer/src).
        // GraphViewer>ImageViewer is the first CROSS-PLUGIN edge: the graph pane
        // paints with the image viewer's rasterizer, which is why its manifest
        // declares `requires: ["image-viewer"]` — the dependency mechanism's
        // first real use.
        'GitPlugin>Event',
        'GitPlugin>Git',
        'GitPlugin>Support',
        'GitPlugin>Plugin',
        'GraphViewer>ImageViewer',
        'GraphViewer>Support',
        'GraphViewer>Ui',
        'GraphViewer>Widget',
        'Help>Editor',
        'Help>Support',
        'Help>Ui',
        'Help>Widget',
        'Help>Workbench',
        'Highlight>Container',
        'Highlight>Editor',
        'Highlight>Event',
        'Highlight>Support',
        'Highlight>Ui',
        'History>Container',
        'History>Editor',
        'History>Support',
        'History>Ui',
        'History>Workbench',
        'I18n>Container',
        'I18n>Ui',
        'Input>Config',
        'Input>Container',
        'Input>Debug',
        'Input>Editor',
        'Input>Git',
        'ImageViewer>Support',
        'ImageViewer>Ui',
        'ImageViewer>Widget',
        'Input>Layout',
        'Input>Overlay',
        'Input>Support',
        'Input>Tree',
        'Input>Ui',
        'Input>Widget',
        'Input>Workbench',
        'Layout>Config',
        'Layout>Container',
        'Layout>Input',
        'Layout>Support',
        'Layout>Ui',
        'Layout>Widget',
        'Layout>Workbench',
        'LocalHistory>Plugin',
        'LocalHistory>Workbench',
        'Lsp>Container',
        'Lsp>Editor',
        'Lsp>Event',
        'Lsp>Support',
        'Lsp>Ui',
        'Lsp>Widget',
        'Lsp>Workbench',
        'Onboarding>Config',
        'Onboarding>Container',
        'Onboarding>Highlight',
        'Onboarding>Plugin',
        'Onboarding>Support',
        'Overlay>Container',
        'Overlay>Editor',
        'Overlay>Layout',
        'Overlay>Onboarding',
        'Overlay>Search',
        'Overlay>Support',
        'Overlay>Ui',
        'Overlay>Widget',
        'Overlay>Workbench',
        'Plugin>Config',
        'Plugin>Container',
        'Plugin>Debug',
        'Plugin>Editor',
        'Plugin>Event',
        'Plugin>Highlight',
        'Plugin>Lsp',
        'Plugin>Search',
        'Plugin>Settings',
        'Plugin>Support',
        'Plugin>Workbench',
        'Run>Event',
        'Run>Container',
        'Run>Support',
        'Run>Terminal',
        'Run>Widget',
        'Run>Workbench',
        'Search>Config',
        'Search>Container',
        'Search>Editor',
        'Search>Event',
        'Search>Highlight',
        'Search>History',
        'Search>Lsp',
        'Search>Overlay',
        'Search>Support',
        'Search>Ui',
        'Search>Widget',
        'Search>Workbench',
        'Settings>Config',
        'Settings>Container',
        'Settings>Debug',
        'Settings>Editor',
        'Settings>Event',
        'Settings>Git',
        'Settings>Highlight',
        'Settings>Layout',
        'Settings>Lsp',
        'Settings>Plugin',
        'Settings>Support',
        'Settings>Ui',
        'Settings>Widget',
        'Settings>Workbench',
        'Support>Container',
        'Support>Ui',
        'Terminal>Support',
        'Terminal>Ui',
        'Todo>Plugin',
        'Todo>Search',
        'Todo>Workbench',
        'Tree>Editor',
        'Tree>Search',
        'Tree>Support',
        'Tree>Widget',
        'Tree>Workbench',
        'Ui>Container',
        'Ui>Editor',
        'Ui>Highlight',
        'Ui>Input',
        'Ui>Overlay',
        'Ui>Support',
        'Widget>Event',
        'Widget>Config',
        'Widget>Container',
        'Widget>Debug',
        'Widget>Editor',
        'Widget>Git',
        'Widget>Help',
        'Widget>Highlight',
        'Widget>Input',
        'Widget>Layout',
        'Widget>Onboarding',
        'Widget>Overlay',
        'Widget>Search',
        'Widget>Settings',
        'Widget>Support',
        'Widget>Terminal',
        'Widget>Tree',
        'Widget>Ui',
        'Widget>Workbench',
        'Workbench>Config',
        'Workbench>Container',
        'Workbench>Editor',
        'Workbench>Event',
        'Workbench>Layout',
        'Workbench>Lsp',
        'Workbench>Overlay',
        'Workbench>Plugin',
        'Workbench>Run',
        'Workbench>Search',
        'Workbench>Support',
        'Workbench>Terminal',
        'Workbench>Tree',
        'Workbench>Ui',
        'Workbench>Widget',
    ];

    /**
     * Multi-member SCCs the audit measured and accepted, each a sorted member
     * list. #1 is the WorkspaceViews registry: closed only by its attach*()
     * setter fields; construction stays a DAG (docs/internals/decisions.md). #2 is
     * degenerate: IdeContainerFactory reads Ide::VERSION as a cache-key salt —
     * a constant, not a collaboration.
     */
    private const array ALLOWED_SCCS = [
        ['Porta\\Container\\IdeContainerFactory', 'Porta\\Ide'],
        ['Porta\\Plugin\\Api\\PluginApi',         'Porta\\Plugin\\Runtime\\PluginManager'],
    ];

    private const int E2E_MAX_TESTS = 60;

    /** @var list<string> */
    private readonly array $allowedEdges;

    /** @var list<list<string>> */
    private readonly array $allowedSccs;

    /**
     * @param list<string>|null       $allowedEdges the pinned namespace graph; defaults to {@see ALLOWED_EDGES}
     * @param list<list<string>>|null $allowedSccs  the pinned cycles; defaults to {@see ALLOWED_SCCS}
     */
    public function __construct(?array $allowedEdges = null, ?array $allowedSccs = null)
    {
        parent::__construct();
        $this->allowedEdges = $allowedEdges ?? self::ALLOWED_EDGES;
        $this->allowedSccs = $allowedSccs ?? self::ALLOWED_SCCS;
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Project root to scan (defaults to the working directory)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $pathArgument */
        $pathArgument = $input->getArgument('path');
        $root = rtrim($pathArgument ?? (string) getcwd(), characters: '/');

        [$nsEdges, $classEdges] = $this->graphs([$root.'/src' => 'Porta\\', ...$this->pluginRoots($root.'/plugins')]);
        $current = array_keys($nsEdges);
        sort($current);
        $sccs = $this->cycles($classEdges);

        $failures = [
            ...$this->edgeDrift($current),
            ...$this->cycleDrift($sccs),
            ...$this->oversizedSuites($root.'/tests/E2e'),
        ];

        if ([] !== $failures) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln('architecture-check: FAILED');
            foreach ($failures as $failure) {
                $errors->writeln('  '.$failure);
            }

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'architecture-check: OK (%d namespace edges, %d known SCCs, E2e suites within bounds)',
            \count($current),
            \count($sccs),
        ));

        return Command::SUCCESS;
    }

    /**
     * The class directories to scan, keyed by directory with the PSR-4 prefix
     * that directory maps to: the project's own `src/`, plus every plugin that
     * ships classes of its own. A plugin's code is this project's code — it is
     * formatted, linted, analysed and covered like the rest, so it is graded
     * here too.
     *
     * The manifest — a plugin's own `composer.json` — is re-read by hand rather
     * than through `PluginManifest` deliberately: this command scans `src/`, so
     * it scans ITSELF, and importing from `Porta\` would grow the very edges it
     * grades.
     *
     * @return array<string, string>
     */
    private function pluginRoots(string $pluginsDir): array
    {
        $roots = [];
        $manifests = glob($pluginsDir.'/*/composer.json');
        foreach (false === $manifests ? [] : $manifests as $path) {
            /** @var array<array-key, mixed>|scalar|null $data */
            $data = json_decode((string) file_get_contents($path), associative: true);
            $autoload = \is_array($data) && \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
            /** @var mixed $relatives */
            foreach (\is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $relatives) {
                if (!\is_string($prefix) || '' === trim($prefix, characters: '\\')) {
                    continue; // a plugin that ships no classes has no graph
                }
                /** @var mixed $relative */
                foreach (\is_array($relatives) ? $relatives : [$relatives] as $relative) {
                    if (!\is_string($relative)) {
                        continue;
                    }
                    $dir = \dirname($path).'/'.trim($relative, characters: '/');
                    if (is_dir($dir)) {
                        $roots[$dir] = trim($prefix, characters: '\\').'\\';
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * Both graphs in one pass over the source trees: namespace-level edges
     * (`From>To`) and class-level ones (FQCN => imported FQCNs). An import
     * counts only when its short name also appears in the comment-stripped
     * body — a `{@see}`-only reference is prose, not coupling.
     *
     * The namespace an edge is attributed to comes from the FQCN, not the path:
     * `plugins/graph-viewer/src/Foo.php` under `Porta\GraphViewer\` is a
     * `GraphViewer` file exactly as `src/Ui/Foo.php` is a `Ui` one.
     *
     * @param array<string, string> $roots directory => PSR-4 prefix
     *
     * @return array{array<string, true>, array<string, list<string>>}
     */
    private function graphs(array $roots): array
    {
        $nsEdges = [];
        $classEdges = [];
        foreach ($roots as $dir => $prefix) {
            $this->scan($dir, $prefix, $nsEdges, $classEdges);
        }

        return [$nsEdges, $classEdges];
    }

    /**
     * @param array<string, true>         $nsEdges
     * @param array<string, list<string>> $classEdges
     */
    private function scan(string $srcDir, string $prefix, array &$nsEdges, array &$classEdges): void
    {
        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = substr($path, \strlen($srcDir) + 1, -4); // strip the src dir and .php
            $fqcn = $prefix.str_replace('/', replace: '\\', subject: $rel);
            // `Porta\Ui\Foo` is a Ui file and `Porta\GraphViewer\Foo` a GraphViewer
            // one; a plugin under a vendor prefix of its own keeps that prefix as
            // its namespace, so its edges are attributed to it and not to Porta.
            $parts = explode(separator: '\\', string: str_starts_with($fqcn, needle: 'Porta\\') ? substr($fqcn, offset: 6) : $fqcn);
            $ns = \count($parts) > 1 ? $parts[0] : null;
            $source = (string) file_get_contents($path);
            $code = $this->stripComments($source);
            $matches = [];
            preg_match_all('/^use (Porta\\\\[A-Za-z\\\\]+);$/m', $source, $matches);
            $body = (string) preg_replace('/^use [^;]+;$/m', replacement: '', subject: $code);
            foreach ($matches[1] as $import) {
                $short = substr((string) strrchr($import, needle: '\\'), offset: 1);
                if (1 !== preg_match('/\\b'.preg_quote($short, delimiter: '/').'\\b/', $body)) {
                    continue;
                }
                $classEdges[$fqcn][] = $import;
                $importNs = explode(separator: '\\', string: $import)[1] ?? '';
                if (null !== $ns && '' !== $importNs && $importNs !== $ns && str_contains($import, needle: '\\'.$importNs.'\\')) {
                    $nsEdges[$ns.'>'.$importNs] = true;
                }
            }
        }
    }

    /**
     * Namespace-ratchet drift, in both directions: a new edge is unjustified
     * coupling, a vanished one is a stale allowlist.
     *
     * @param list<string> $current
     *
     * @return list<string>
     */
    private function edgeDrift(array $current): array
    {
        $failures = [];
        foreach (array_diff($current, $this->allowedEdges) as $edge) {
            $failures[] = "namespace: NEW edge {$edge} — new coupling; if deliberate, add it to ALLOWED_EDGES in this PR";
        }
        foreach (array_diff($this->allowedEdges, $current) as $edge) {
            $failures[] = "namespace: edge {$edge} no longer exists — remove it from ALLOWED_EDGES (a stale allowlist is not a gate)";
        }

        return $failures;
    }

    /**
     * @param list<list<string>> $sccs
     *
     * @return list<string>
     */
    private function cycleDrift(array $sccs): array
    {
        $failures = [];
        foreach ($sccs as $scc) {
            if (\in_array($scc, $this->allowedSccs, strict: true)) {
                continue;
            }
            $failures[] = 'classes: new circular dependency — '.implode(' <-> ', $scc).' (re-opening acyclicity needs new evidence, recorded in docs/internals/decisions.md)';
        }
        foreach ($this->allowedSccs as $allowed) {
            if (\in_array($allowed, $sccs, strict: true)) {
                continue;
            }
            $failures[] = 'classes: allowed SCC {'.implode(', ', $allowed).'} no longer exists — remove it from ALLOWED_SCCS';
        }

        return $failures;
    }

    /** @return list<string> */
    private function oversizedSuites(string $e2eDir): array
    {
        $failures = [];
        foreach ($this->phpFiles($e2eDir) as $path) {
            $count = (int) preg_match_all('/^    public function test/m', (string) file_get_contents($path));
            if ($count <= self::E2E_MAX_TESTS) {
                continue;
            }
            $failures[] = "e2e: {$path} has {$count} test methods (max ".self::E2E_MAX_TESTS.') — split it before it regrows the monolith';
        }

        return $failures;
    }

    /**
     * Tarjan over the class graph, keeping only multi-member components —
     * a class importing itself is not a cycle worth pinning.
     *
     * @param array<string, list<string>> $classEdges
     *
     * @return list<list<string>>
     */
    private function cycles(array $classEdges): array
    {
        $index = 0;
        $indices = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $sccs = [];
        $strongconnect = static function (string $v) use (&$strongconnect, &$index, &$indices, &$low, &$onStack, &$stack, &$sccs, $classEdges): void {
            $indices[$v] = $index;
            $low[$v] = $index;
            ++$index;
            $stack[] = $v;
            $onStack[$v] = true;
            foreach ($classEdges[$v] ?? [] as $w) {
                if (!\array_key_exists($w, $classEdges) && !\array_key_exists($w, $indices)) {
                    $indices[$w] = \PHP_INT_MAX; // leaf import (an interface with no file of its own, etc.)
                    continue;
                }
                if (!\array_key_exists($w, $indices)) {
                    $strongconnect($w);
                    $low[$v] = min($low[$v], $low[$w]);
                    continue;
                }
                if ($onStack[$w] ?? false) {
                    $low[$v] = min($low[$v], $indices[$w]);
                }
            }
            // Only a component ROOT pops a component — Tarjan's invariant, and
            // genuinely variable at runtime: the loop above lowers $low[$v]
            // through min() for every back-edge. The analyzer cannot follow
            // those by-reference writes and reads the comparison as constant.
            // @mago-expect analysis:redundant-condition -- $low[$v] is mutated by the by-reference min() writes above.
            // @mago-expect analysis:redundant-comparison -- same: the operands diverge whenever this node is not a component root.
            if ($low[$v] === $indices[$v]) {
                $scc = self::popComponent($v, $stack, $onStack);
                if (\count($scc) > 1) {
                    sort($scc);
                    $sccs[] = $scc;
                }
            }
        };
        foreach (array_keys($classEdges) as $v) {
            if (\array_key_exists($v, $indices)) {
                continue;
            }
            $strongconnect($v);
        }

        return $sccs;
    }

    /**
     * Pop one strongly-connected component off the Tarjan stack, up to and
     * including its root.
     *
     * @param list<string>        $stack
     * @param array<string, bool> $onStack
     *
     * @return list<string>
     */
    private static function popComponent(string $root, array &$stack, array &$onStack): array
    {
        $scc = [];
        do {
            $popped = (string) array_pop($stack);
            $onStack[$popped] = false;
            $scc[] = $popped;
        } while ($popped !== $root);

        return $scc;
    }

    /**
     * Every .php file under $dir, sorted; a missing directory yields none (a
     * scanned tree need not have both src/ and tests/E2e).
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /** The source with comments and docblocks removed, so prose cannot fake a code edge. */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $out .= $token;
                continue;
            }
            if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                continue;
            }
            $out .= $token[1];
        }

        return $out;
    }
}
