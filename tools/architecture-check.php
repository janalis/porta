<?php

declare(strict_types=1);

/**
 * Architecture gate: the structural facts the six-round SOLID audit
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
 *   3. E2e suite size — no tests/E2e file may exceed 60 test methods. The
 *      retired IdeEdgeCasesTest monolith had 187 and pinned two lint
 *      ratchets; the largest suite today has 42.
 *
 * Usage: php tools/architecture-check.php   (exit 0 clean, 1 on drift)
 */

/** The audited namespace import graph, sorted, as 'From>To'. */
const ALLOWED_EDGES = [
    'Bootstrap>Config',
    'Bootstrap>Container',
    'Bootstrap>Editor',
    'Bootstrap>Help',
    'Bootstrap>Image',
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
    'Command>History',
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
    'Editor>Git',
    'Editor>Highlight',
    'Editor>History',
    'Editor>Image',
    'Editor>Lsp',
    'Editor>Search',
    'Editor>Support',
    'Editor>Ui',
    'Editor>Widget',
    'Editor>Workbench',
    'Event>Editor',
    'Event>Widget',
    'Git>Container',
    'Git>Editor',
    'Git>Event',
    'Git>Layout',
    'Git>Support',
    'Git>Ui',
    'Git>Widget',
    'Git>Workbench',
    'Help>Editor',
    'Help>Support',
    'Help>Ui',
    'Help>Widget',
    'Help>Workbench',
    'Highlight>Editor',
    'Highlight>Event',
    'Highlight>Support',
    'Highlight>Ui',
    'History>Container',
    'History>Editor',
    'History>Lsp',
    'History>Support',
    'History>Ui',
    'History>Workbench',
    'I18n>Container',
    'I18n>Ui',
    'Image>Editor',
    'Image>Ui',
    'Image>Widget',
    'Input>Config',
    'Input>Container',
    'Input>Debug',
    'Input>Editor',
    'Input>Git',
    'Input>Layout',
    'Input>Overlay',
    'Input>Support',
    'Input>Tree',
    'Input>Ui',
    'Input>Widget',
    'Input>Workbench',
    'Layout>Config',
    'Layout>Container',
    'Layout>Git',
    'Layout>Input',
    'Layout>Support',
    'Layout>Ui',
    'Layout>Widget',
    'Layout>Workbench',
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
    'Onboarding>Settings',
    'Onboarding>Support',
    'Overlay>Container',
    'Overlay>Layout',
    'Overlay>Onboarding',
    'Overlay>Search',
    'Overlay>Support',
    'Overlay>Ui',
    'Overlay>Widget',
    'Overlay>Workbench',
    'Plugin>Config',
    'Plugin>Container',
    'Plugin>Editor',
    'Plugin>Event',
    'Plugin>Highlight',
    'Plugin>Lsp',
    'Plugin>Search',
    'Plugin>Settings',
    'Plugin>Support',
    'Plugin>Widget',
    'Plugin>Workbench',
    'Run>Container',
    'Run>Support',
    'Run>Terminal',
    'Run>Widget',
    'Run>Workbench',
    'Search>Config',
    'Search>Container',
    'Search>Editor',
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
    'Settings>Search',
    'Settings>Support',
    'Settings>Ui',
    'Settings>Widget',
    'Settings>Workbench',
    'Support>Container',
    'Support>Ui',
    'Terminal>Support',
    'Terminal>Ui',
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
    'Widget>Config',
    'Widget>Container',
    'Widget>Debug',
    'Widget>Editor',
    'Widget>Git',
    'Widget>Help',
    'Widget>Highlight',
    'Widget>Image',
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
    'Workbench>Debug',
    'Workbench>Editor',
    'Workbench>Event',
    'Workbench>Git',
    'Workbench>Layout',
    'Workbench>Lsp',
    'Workbench>Overlay',
    'Workbench>Plugin',
    'Workbench>Run',
    'Workbench>Search',
    'Workbench>Settings',
    'Workbench>Support',
    'Workbench>Terminal',
    'Workbench>Tree',
    'Workbench>Ui',
    'Workbench>Widget',
];

/**
 * Multi-member SCCs the audit measured and accepted, each a sorted member
 * list. #1 is the WorkspaceViews registry: closed only by its attach*()
 * setter fields; construction stays a DAG (CLAUDE.md, round 6). #2 is
 * degenerate: IdeContainerFactory reads Ide::VERSION as a cache-key salt —
 * a constant, not a collaboration.
 */
const ALLOWED_SCCS = [
    [
        'Porta\\Git\\GitViewController',
        'Porta\\Layout\\LayoutController',
        'Porta\\Settings\\SettingsViewController',
        'Porta\\Widget\\CodeEditorWidget',
        'Porta\\Workbench\\Dock\\BottomDock',
        'Porta\\Workbench\\EditorArea',
        'Porta\\Workbench\\WorkspaceViews',
    ],
    [
        'Porta\\Container\\IdeContainerFactory',
        'Porta\\Ide',
    ],
];

const E2E_MAX_TESTS = 60;

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && 'php' === $f->getExtension()) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) {
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }

    return $out;
}

$failures = [];

// --- 1 + 2: build both graphs in one pass over src/ ------------------------
$nsEdges = [];
$classEdges = [];
foreach (phpFiles('src') as $path) {
    $rel = substr($path, 4, -4); // strip src/ and .php
    $fqcn = 'Porta\\'.str_replace('/', '\\', $rel);
    $parts = explode('/', $rel);
    $ns = count($parts) > 1 ? $parts[0] : null;
    $src = (string) file_get_contents($path);
    $code = stripComments($src);
    preg_match_all('/^use (Porta\\\\[A-Za-z\\\\]+);$/m', $src, $m);
    foreach ($m[1] as $import) {
        $short = substr((string) strrchr($import, '\\'), 1);
        // an import is a code edge only when its short name appears outside
        // the use statement itself in comment-stripped code
        $body = (string) preg_replace('/^use [^;]+;$/m', '', $code);
        if (1 !== preg_match('/\\b'.preg_quote($short, '/').'\\b/', $body)) {
            continue;
        }
        $classEdges[$fqcn][] = $import;
        $importNs = explode('\\', $import)[1] ?? '';
        if (null !== $ns && '' !== $importNs && $importNs !== $ns && str_contains($import, '\\'.$importNs.'\\')) {
            $nsEdges[$ns.'>'.$importNs] = true;
        }
    }
}

// namespace ratchet (both directions of drift fail)
$current = array_keys($nsEdges);
sort($current);
foreach (array_diff($current, ALLOWED_EDGES) as $edge) {
    $failures[] = "namespace: NEW edge {$edge} — new coupling; if deliberate, add it to ALLOWED_EDGES in this PR";
}
foreach (array_diff(ALLOWED_EDGES, $current) as $edge) {
    $failures[] = "namespace: edge {$edge} no longer exists — remove it from ALLOWED_EDGES (a stale allowlist is not a gate)";
}

// Tarjan SCC over class edges
$index = 0;
$indices = [];
$low = [];
$onStack = [];
$stack = [];
$sccs = [];
$strongconnect = function (string $v) use (&$strongconnect, &$index, &$indices, &$low, &$onStack, &$stack, &$sccs, $classEdges): void {
    $indices[$v] = $index;
    $low[$v] = $index;
    ++$index;
    $stack[] = $v;
    $onStack[$v] = true;
    foreach ($classEdges[$v] ?? [] as $w) {
        if (!isset($classEdges[$w]) && !isset($indices[$w])) {
            $indices[$w] = PHP_INT_MAX; // leaf import (interface w/o file match etc.)
            continue;
        }
        if (!isset($indices[$w])) {
            $strongconnect($w);
            $low[$v] = min($low[$v], $low[$w]);
        } elseif ($onStack[$w] ?? false) {
            $low[$v] = min($low[$v], $indices[$w]);
        }
    }
    if ($low[$v] === $indices[$v]) {
        $scc = [];
        do {
            $w = array_pop($stack);
            $onStack[$w] = false;
            $scc[] = $w;
        } while ($w !== $v);
        if (count($scc) > 1) {
            sort($scc);
            $sccs[] = $scc;
        }
    }
};
foreach (array_keys($classEdges) as $v) {
    if (!isset($indices[$v])) {
        $strongconnect($v);
    }
}
foreach ($sccs as $scc) {
    if (!in_array($scc, ALLOWED_SCCS, true)) {
        $failures[] = 'classes: new circular dependency — '.implode(' <-> ', $scc).' (re-opening acyclicity needs the CLAUDE.md bar: new evidence, recorded)';
    }
}
foreach (ALLOWED_SCCS as $allowed) {
    if (!in_array($allowed, $sccs, true)) {
        $failures[] = 'classes: allowed SCC {'.implode(', ', $allowed).'} no longer exists — remove it from ALLOWED_SCCS';
    }
}

// --- 3: E2e suite size ------------------------------------------------------
foreach (phpFiles('tests/E2e') as $path) {
    $count = preg_match_all('/^    public function test/m', (string) file_get_contents($path));
    if ($count > E2E_MAX_TESTS) {
        $failures[] = "e2e: {$path} has {$count} test methods (max ".E2E_MAX_TESTS.') — split it before it regrows the monolith';
    }
}

if ([] !== $failures) {
    fwrite(STDERR, "architecture-check: FAILED\n  ".implode("\n  ", $failures)."\n");
    exit(1);
}
echo 'architecture-check: OK ('.count($current).' namespace edges, '.count($sccs)." known SCCs, E2e suites within bounds)\n";
