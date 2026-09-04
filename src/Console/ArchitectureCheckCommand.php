<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

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
 *   3. Suite size — no tests/E2e or plugins/<name>/tests file may exceed
 *      E2E_MAX_TESTS methods. The retired IdeEdgeCasesTest monolith had 187
 *      and pinned two lint ratchets; the plugin roots are graded too because
 *      testing.md SENDS plugin-driving E2e tests there — the documented
 *      migration path must not be the ratchet's blind spot.
 *   4. No test may chain off an inline `new TempProject(...)` temporary —
 *      PHP frees the object (whose destructor removes the fixture dir) the
 *      moment `->dir`/`->path()` has been read, BEFORE the enclosing call
 *      runs. Every such assertion silently degenerates to the missing-dir
 *      case, and code that then writes into the freed path recreates it as
 *      an orphan (12k of them were found in TMPDIR). Hold the project in a
 *      variable. Comments are tokenizer-exempt (prose may name the trap);
 *      the scan is single-line only — a multi-line construction dodges it,
 *      but none exists and review catches what a ratchet misses.
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
#[AsCommand(
    name: 'arch',
    description: "Enforce Porta's namespace, acyclicity and E2e-size ratchets",
    help: <<<'HELP'
        Scans src/ and plugins/*/src for namespace edges, class-level cycles and
        oversized E2e suites, and fails a ratchet in EITHER direction: a new edge
        or cycle needs justifying in this file's pins, and a pin that no longer
        exists must be removed from it.

          <info>arch</info>                scan the working directory
          <info>arch /path/to/project</info>  scan a specific project root
        HELP,
    usages: ['/path/to/project'],
)]
final class ArchitectureCheckCommand extends Command
{
    use CommandErrorStream;

    /** The audited namespace import graph, sorted, as 'From>To'. */
    private const array ALLOWED_EDGES = [
        'Bootstrap>Config',
        'Bootstrap>Container',
        'Bootstrap>Editor',
        'Bootstrap>Help',
        'Bootstrap>Overlay',
        'Bootstrap>Plugin',
        'Bootstrap>Run',
        'Bootstrap>Support',
        'Bootstrap>Widget',
        'Bootstrap>Workbench',
        'Command>Config',
        'Command>Container',
        'Command>Debug',
        'Command>Editor',
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
        'Console>Support',
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
        'Editor>History',
        'Editor>Lsp',
        'Editor>Search',
        'Editor>Support',
        'Editor>Ui',
        'Editor>Widget',
        'Editor>Workbench',
        'Event>Editor',
        'Event>Ui',
        'Event>Workbench',
        'Event>Widget',
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
        // The git plugin owns the whole git feature now: the view and its five
        // widgets, the branch cell, the menu, the palette verbs, the settings.
        // `Porta\Git` no longer exists, and neither does any core edge INTO
        // git — which is what the seven vanished `*>Git` entries above record.
        // What is left is a plugin reaching for core's toolkits, the allowed
        // direction, and the same set `Help` and `GraphViewer` already reach for.
        'GitPlugin>Event',
        'GitPlugin>Highlight',
        'GitPlugin>Input',
        'GitPlugin>Layout',
        'GitPlugin>Overlay',
        'GitPlugin>Support',
        'GitPlugin>Plugin',
        'GitPlugin>Ui',
        'GitPlugin>Widget',
        'GitPlugin>Workbench',
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
        'History>Event',
        'History>Support',
        'History>Ui',
        'History>Workbench',
        'I18n>Container',
        'I18n>Ui',
        'Input>Config',
        'Input>Container',
        'Input>Debug',
        'Input>Editor',
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
        'Plugin>Overlay',
        'Plugin>Search',
        'Plugin>Settings',
        'Plugin>Support',
        'Plugin>Tree',
        'Plugin>Widget',
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
        'Settings>Highlight',
        'Settings>Input',
        'Settings>Layout',
        'Settings>Lsp',
        'Settings>Plugin',
        // The "Search vendor/ files" toggle applies straight to the shared
        // FileIndex — the SettingsController's live-apply pattern.
        'Settings>Search',
        'Settings>Support',
        'Settings>Ui',
        'Settings>Widget',
        'Settings>Workbench',
        'Support>Container',
        'Support>Ui',
        // The symfony-lsp plugin's one core reach: Argv::split for the
        // php-command setting. Its plugin.php (outside the namespace graph)
        // does the PluginApi wiring.
        'SymfonyLsp>Support',
        'Terminal>Support',
        'Terminal>Ui',
        'Todo>Plugin',
        'Todo>Search',
        'Todo>Workbench',
        'Tree>Container',
        'Tree>Editor',
        'Tree>Event',
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
        'Widget>Help',
        'Widget>Highlight',
        'Widget>Input',
        'Widget>Layout',
        // The editor widget picks its scope source: the offline PHP scan, or the
        // language server's symbols for every other language.
        'Widget>Lsp',
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
        'Workbench>Input',
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
     * list. Both are degenerate: IdeContainerFactory reads Ide::VERSION as a
     * cache-key salt (a constant, not a collaboration), and PluginApi/
     * PluginManager hold each other because the API's every verb reports
     * through the manager's fault barrier.
     *
     * The third — the seven-class ring around the old view registry — is gone
     * rather than pinned: its two concrete controller fields were the whole of
     * it, and a string-keyed registry has neither.
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

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Project root to scan (defaults to the working directory)')]
        ?string $path = null,
    ): int {
        $root = rtrim($path ?? (string) getcwd(), characters: '/');

        [$nsEdges, $classEdges] = $this->graphs([$root.'/src' => 'Porta\\', ...$this->pluginRoots($root.'/plugins')]);
        $current = array_keys($nsEdges);
        sort($current);
        $sccs = $this->cycles($classEdges);

        $failures = [
            ...$this->edgeDrift($current),
            ...$this->cycleDrift($sccs),
            ...$this->oversizedSuites($root.'/tests/E2e', ...$this->pluginTestDirs($root.'/plugins')),
            ...$this->inlineTempProjects($root.'/tests', ...$this->pluginTestDirs($root.'/plugins')),
        ];

        if ([] !== $failures) {
            $errors = self::errorOutput($output);
            $io = new SymfonyStyle($input, $errors);
            $io->error('architecture-check: FAILED');
            $io->listing($failures);

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'architecture-check: OK (%d namespace edges, %d known SCCs, E2e suites within bounds, no inline TempProject temporaries)',
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
    private function oversizedSuites(string ...$suiteDirs): array
    {
        $failures = [];
        foreach ($suiteDirs as $dir) {
            foreach ($this->phpFiles($dir) as $path) {
                // #[Test] counts too — one attributed method would otherwise
                // silently zero a file's count.
                $count = (int) preg_match_all('/^    (?:public function test|#\[Test\])/m', (string) file_get_contents($path));
                if ($count <= self::E2E_MAX_TESTS) {
                    continue;
                }
                $failures[] = "e2e: {$path} has {$count} test methods (max ".self::E2E_MAX_TESTS.') — split it before it regrows the monolith';
            }
        }

        return $failures;
    }

    /**
     * Flag any test line that chains off an inline `new TempProject(...)`:
     * the temporary is freed — and its fixture dir removed — as soon as the
     * property/method access has been read, before the enclosing call runs.
     * The scan runs over comment-stripped source (the tokenizer, not a line
     * prefix heuristic), so prose may name the trap anywhere — a whole-line
     * comment, a trailing one, a docblock — without tripping it; the
     * stripper keeps dropped comments' newlines, so reported line numbers
     * stay true.
     *
     * @return list<string>
     */
    private function inlineTempProjects(string ...$suiteDirs): array
    {
        $failures = [];
        foreach ($suiteDirs as $dir) {
            foreach ($this->phpFiles($dir) as $path) {
                foreach (explode("\n", $this->stripComments((string) file_get_contents($path))) as $i => $line) {
                    if (1 !== preg_match('/new TempProject\([^;]*\)->/', $line)) {
                        continue;
                    }

                    $failures[] = \sprintf(
                        'tests: %s:%d chains off an inline `new TempProject(...)` — the temporary is freed (and its fixture dir removed) before the enclosing call runs; hold it in a variable',
                        $path,
                        $i + 1,
                    );
                }
            }
        }

        return $failures;
    }

    /**
     * Every plugin's own tests directory — the suites the size ratchet grades
     * beside tests/E2e, since that is where a plugin-driving E2e belongs.
     *
     * @return list<string>
     */
    private function pluginTestDirs(string $pluginsDir): array
    {
        $dirs = glob($pluginsDir.'/*/tests', \GLOB_ONLYDIR);

        return false === $dirs ? [] : $dirs;
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
            // Two codes for one site: suppressing the condition unmasks the comparison, which
            // is the same false positive read a second way. Verified 2026-09-02 by removing
            // both — the pair is needed, neither alone.
            // @mago-expect analysis:redundant-comparison -- see below
            // @mago-expect analysis:redundant-condition -- $low[$v] is mutated by the min()
            // writes above, through a by-reference `use` in a recursive closure, which the
            // analyzer does not follow. Re-checked 2026-09-02: restructuring real Tarjan to
            // satisfy it would risk the gate everything else depends on.
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
        // Dot files and VCS dirs stay visible, matching the raw walker this
        // replaced — the scanned trees carry neither, and a gate must not
        // narrow its input set silently. The regex name form on purpose:
        // Finder's '*.php' glob follows shell semantics, where a leading *
        // never matches a leading dot, ignoreDotFiles(false) notwithstanding.
        foreach (new Finder()
            ->files()
            ->in($dir)
            ->name('/\.php$/')
            ->ignoreDotFiles(false)
            ->ignoreVCS(false) as $file) {
            $files[] = $file->getPathname();
        }
        // Explicit pathname sort, not Finder::sortByName() — that key is the
        // realpath, which diverges under symlinks, and gate output is a byte
        // contract.
        sort($files);

        return $files;
    }

    /**
     * The source with comments and docblocks removed, so prose cannot fake a
     * code edge. A dropped comment's newlines are kept, so line numbers in
     * the stripped text still name the original lines — the TempProject scan
     * reports by line.
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $out .= $token;
                continue;
            }
            if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                $out .= str_repeat("\n", substr_count($token[1], needle: "\n"));
                continue;
            }
            $out .= $token[1];
        }

        return $out;
    }
}
