<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Support\JsonFile;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * The architecture gate: the structural facts the six-round SOLID audit
 * established (July 2026), enforced as a ratchet. Five checks:
 *
 *   1. Namespace edges — the exact import graph between top-level src/
 *      namespaces, pinned in config/arch-allowlist.php. A NEW edge fails (new
 *      coupling: justify it by adding it there in the same PR, where it is
 *      reviewable); a listed
 *      edge that no longer exists ALSO fails (tighten the list — a stale
 *      allowlist is not a gate, the mago.toml doctrine).
 *   2. Class-level acyclicity — no multi-member strongly-connected component
 *      may exist except the pinned ones. Docblocks and comments are
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
 *   5. No test outside tests/Support reflects private state. testing.md
 *      blesses `Probe` (and its typed wrappers) as the one reader, so the
 *      day a docblock or a field is renamed, one seam breaks loudly instead
 *      of a scatter of `ReflectionProperty`s failing one by one. Reading a
 *      public SIGNATURE (`->getParameters()`) is not private state and
 *      passes; `ReflectionClass` is not matched at all, since its public
 *      reads (method lists, public constants) are what the doc gates need.
 *      Same comment-stripped, single-line scan as check 4.
 *
 * Runs from the dev console (`bin/porta-dev`, via `composer arch`): a
 * maintainer gate over Porta\'s own tree, which is why the vendor prefix is
 * baked in and the pins ship beside it. The scan root is an argument and the pins are
 * constructor-injectable so the checker can be driven over fixture trees —
 * which is also how it earns its own 100% coverage. It scans src/, so it
 * scans itself: keep it importing nothing from `Porta\` or it grows the
 * very edges it grades.
 */
#[AsCommand(
    name: 'arch',
    description: "Enforce Porta's namespace, acyclicity and E2e-size ratchets",
    help: <<<'HELP'
        Scans src/ and plugins/*/src for namespace edges, class-level cycles and
        oversized E2e suites, and fails a ratchet in EITHER direction: a new edge
        or cycle needs justifying in config/arch-allowlist.php, and a pin that
        no longer exists must be removed from it.

          <info>arch</info>                scan the working directory
          <info>arch /path/to/project</info>  scan a specific project root
        HELP,
    usages: ['/path/to/project'],
)]
final class ArchitectureCheckCommand extends Command
{
    use CommandErrorStream;

    private const int E2E_MAX_TESTS = 60;

    /** @var list<string> */
    private readonly array $allowedEdges;

    /** @var list<list<string>> */
    private readonly array $allowedSccs;

    /**
     * @param list<string>|null       $allowedEdges the pinned namespace graph; defaults to config/arch-allowlist.php's
     * @param list<list<string>>|null $allowedSccs  the pinned cycles; defaults to config/arch-allowlist.php's
     */
    public function __construct(?array $allowedEdges = null, ?array $allowedSccs = null)
    {
        parent::__construct();
        $pinned = null === $allowedEdges || null === $allowedSccs ? self::pinned() : null;
        $this->allowedEdges = $allowedEdges ?? $pinned['edges'] ?? [];
        $this->allowedSccs = $allowedSccs ?? $pinned['sccs'] ?? [];
    }

    /**
     * The pins, as config/arch-allowlist.php holds them.
     *
     * @return array{edges: list<string>, sccs: list<list<string>>}
     */
    private static function pinned(): array
    {
        // @mago-expect lint:inline-variable-return -- the variable is what carries the type require() cannot
        /** @var array{edges: list<string>, sccs: list<list<string>>} $pinned */
        $pinned = require \dirname(__DIR__, levels: 2).'/config/arch-allowlist.php';

        return $pinned;
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Project root to scan (defaults to the working directory)')]
        ?string $path = null,
    ): int {
        $root = \rtrim($path ?? (string) \getcwd(), characters: '/');

        [$nsEdges, $classEdges] = $this->graphs([$root.'/src' => 'Porta\\', ...$this->pluginRoots($root.'/plugins')]);
        $current = \array_keys($nsEdges);
        \sort($current);
        $sccs = $this->cycles($classEdges);

        $failures = [
            ...$this->edgeDrift($current, $nsEdges),
            ...$this->cycleDrift($sccs),
            ...$this->oversizedSuites($root.'/tests/E2e', ...$this->pluginTestDirs($root.'/plugins')),
            ...$this->inlineTempProjects($root.'/tests', ...$this->pluginTestDirs($root.'/plugins')),
            ...$this->privateReflection($root.'/tests/Support/', $root.'/tests', ...$this->pluginTestDirs($root.'/plugins')),
        ];

        if ([] !== $failures) {
            $errors = self::errorOutput($output);
            $io = new SymfonyStyle($input, $errors);
            $io->error('architecture-check: FAILED');
            $io->listing($failures);

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'architecture-check: OK (%d namespace edges, %d known SCCs, E2e suites within bounds, no inline TempProject temporaries, no private reflection outside tests/Support)',
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
        $manifests = \glob($pluginsDir.'/*/composer.json');
        foreach (false === $manifests ? [] : $manifests as $path) {
            $data = JsonFile::read($path);
            $autoload = \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
            /** @var mixed $relatives */
            foreach (\is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $relatives) {
                if (!\is_string($prefix) || '' === \trim($prefix, characters: '\\')) {
                    continue; // a plugin that ships no classes has no graph
                }
                /** @var mixed $relative */
                foreach (\is_array($relatives) ? $relatives : [$relatives] as $relative) {
                    if (!\is_string($relative)) {
                        continue;
                    }
                    $dir = \dirname($path).'/'.\trim($relative, characters: '/');
                    if (\is_dir($dir)) {
                        $roots[$dir] = \trim($prefix, characters: '\\').'\\';
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
     * `GraphViewer` file exactly as `src/Ui/Foo.php` is a `Ui` one. Each
     * namespace edge keeps the class imports that make it, so a new one can
     * be reported with the `use` line to look at.
     *
     * @param array<string, string> $roots directory => PSR-4 prefix
     *
     * @return array{array<string, list<string>>, array<string, list<string>>}
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
     * @param array<string, list<string>> $nsEdges
     * @param array<string, list<string>> $classEdges
     */
    private function scan(string $srcDir, string $prefix, array &$nsEdges, array &$classEdges): void
    {
        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = \substr($path, \strlen($srcDir) + 1, -4); // strip the src dir and .php
            $fqcn = $prefix.\str_replace('/', replace: '\\', subject: $rel);
            // `Porta\Ui\Foo` is a Ui file and `Porta\GraphViewer\Foo` a GraphViewer
            // one; a plugin under a vendor prefix of its own keeps that prefix as
            // its namespace, so its edges are attributed to it and not to Porta.
            $parts = \explode(separator: '\\', string: \str_starts_with($fqcn, needle: 'Porta\\') ? \substr($fqcn, offset: 6) : $fqcn);
            $ns = \count($parts) > 1 ? $parts[0] : null;
            $source = (string) \file_get_contents($path);
            $code = $this->stripComments($source);
            $matches = [];
            \preg_match_all('/^use (Porta\\\\[A-Za-z\\\\]+);$/m', $source, $matches);
            $body = (string) \preg_replace('/^use [^;]+;$/m', replacement: '', subject: $code);
            foreach ($matches[1] as $import) {
                $short = \substr((string) \strrchr($import, needle: '\\'), offset: 1);
                if (1 !== \preg_match('/\\b'.\preg_quote($short, delimiter: '/').'\\b/', $body)) {
                    continue;
                }
                $classEdges[$fqcn][] = $import;
                $importNs = \explode(separator: '\\', string: $import)[1] ?? '';
                if (null !== $ns && '' !== $importNs && $importNs !== $ns && \str_contains($import, needle: '\\'.$importNs.'\\')) {
                    $nsEdges[$ns.'>'.$importNs][] = "{$fqcn} uses {$import}";
                }
            }
        }
    }

    /**
     * Namespace-ratchet drift, in both directions: a new edge is unjustified
     * coupling, a vanished one is a stale allowlist. A new edge names the
     * imports that make it: the fix is either one of those `use` lines or an
     * allowlist entry, and the edge alone left an agent grepping for which.
     *
     * @param list<string>                $current
     * @param array<string, list<string>> $nsEdges edge => the class imports that make it
     *
     * @return list<string>
     */
    private function edgeDrift(array $current, array $nsEdges): array
    {
        $failures = [];
        foreach (\array_diff($current, $this->allowedEdges) as $edge) {
            $failures[] = "namespace: NEW edge {$edge} — new coupling; if deliberate, add it to config/arch-allowlist.php in this PR. Made by: ".\implode('; ', $nsEdges[$edge] ?? []);
        }
        foreach (\array_diff($this->allowedEdges, $current) as $edge) {
            $failures[] = "namespace: edge {$edge} no longer exists — remove it from config/arch-allowlist.php (a stale allowlist is not a gate)";
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
            $failures[] = 'classes: new circular dependency — '.\implode(' <-> ', $scc).' (re-opening acyclicity needs new evidence, recorded in docs/internals/decisions.md)';
        }
        foreach ($this->allowedSccs as $allowed) {
            if (\in_array($allowed, $sccs, strict: true)) {
                continue;
            }
            $failures[] = 'classes: allowed SCC {'.\implode(', ', $allowed).'} no longer exists — remove it from config/arch-allowlist.php';
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
                $count = (int) \preg_match_all('/^    (?:public function test|#\[Test\])/m', (string) \file_get_contents($path));
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
                foreach (\explode("\n", $this->stripComments((string) \file_get_contents($path))) as $i => $line) {
                    if (1 !== \preg_match('/new TempProject\([^;]*\)->/', $line)) {
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
     * Flag any test line outside $support that reflects a private member —
     * `new ReflectionProperty`/`ReflectionMethod`/`ReflectionClassConstant`,
     * or `->getConstant()` — instead of going through `Probe`. A method
     * reflected only for its `->getParameters()` reads a public signature
     * and passes.
     *
     * @return list<string>
     */
    private function privateReflection(string $support, string ...$suiteDirs): array
    {
        $failures = [];
        foreach ($suiteDirs as $dir) {
            foreach ($this->phpFiles($dir) as $path) {
                if (\str_starts_with($path, $support)) {
                    continue;
                }
                foreach (\explode("\n", $this->stripComments((string) \file_get_contents($path))) as $i => $line) {
                    $reflects = 1 === \preg_match('/new \\\\Reflection(?:Property|Method|ClassConstant)\(|->getConstant\(/', $line);
                    if (!$reflects || \str_contains($line, '->getParameters()')) {
                        continue;
                    }

                    $failures[] = \sprintf(
                        'tests: %s:%d reflects private state outside tests/Support — read it through Probe (get, invoke, getStatic, invokeStatic)',
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
        $dirs = \glob($pluginsDir.'/*/tests', \GLOB_ONLYDIR);

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
                    $low[$v] = \min($low[$v], $low[$w]);
                    continue;
                }
                if ($onStack[$w] ?? false) {
                    $low[$v] = \min($low[$v], $indices[$w]);
                }
            }
            // Only a component ROOT pops a component — Tarjan's invariant, and
            // genuinely variable at runtime: the loop above lowers $low[$v]
            // through min() for every back-edge.
            if ($low[$v] === $indices[$v]) {
                $scc = self::popComponent($v, $stack, $onStack);
                if (\count($scc) > 1) {
                    \sort($scc);
                    $sccs[] = $scc;
                }
            }
        };
        foreach (\array_keys($classEdges) as $v) {
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
            $popped = (string) \array_pop($stack);
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
        if (!\is_dir($dir)) {
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
        \sort($files);

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
        foreach (\token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $out .= $token;
                continue;
            }
            if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                $out .= \str_repeat("\n", \substr_count($token[1], needle: "\n"));
                continue;
            }
            $out .= $token[1];
        }

        return $out;
    }
}
