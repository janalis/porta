<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Tests\Support\TempProject;

final class EditorConfigResolverTest extends TestCase
{
    private function resolve(TempProject $project, string $relative): ResolvedEditorConfig
    {
        return new EditorConfigResolver()->resolve($project->path($relative));
    }

    public function testAppliesRootConfig(): void
    {
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\nindent_style = space\nindent_size = 2\n",
            'a.py' => 'x',
        ]);

        $c = $this->resolve($project, 'a.py');
        $this->assertFalse($c->usesTabs);
        $this->assertSame(2, $c->indentSize);
    }

    public function testNearerFileWinsAndRootStopsTheWalk(): void
    {
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\nindent_style = space\nindent_size = 2\n",
            'sub/.editorconfig' => "[*]\nindent_size = 4\n",
            'sub/a.py' => 'x',
        ]);

        $c = $this->resolve($project, 'sub/a.py');
        $this->assertSame(4, $c->indentSize, 'the nearer .editorconfig overrides indent_size');
        $this->assertFalse($c->usesTabs, 'unspecified nearer prop falls back to the ancestor');
    }

    public function testRootTrueStopsBeforeAncestors(): void
    {
        $project = new TempProject([
            '.editorconfig' => "[*]\nindent_style = tab\n",
            'sub/.editorconfig' => "root = true\n[*]\nindent_size = 2\n",
            'sub/a.py' => 'x',
        ]);

        $c = $this->resolve($project, 'sub/a.py');
        $this->assertSame(2, $c->indentSize);
        $this->assertNull($c->usesTabs, 'the ancestor above the root=true file is not consulted');
    }

    public function testLaterSectionWins(): void
    {
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\nindent_size = 2\n[*.py]\nindent_size = 8\n",
            'a.py' => 'x',
        ]);

        $this->assertSame(8, $this->resolve($project, 'a.py')->indentSize);
    }

    public function testUnsetClearsInheritedProperty(): void
    {
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\nindent_size = 4\n[*.py]\nindent_size = unset\n",
            'a.py' => 'x',
        ]);

        $this->assertNull($this->resolve($project, 'a.py')->indentSize);
    }

    public function testNonMatchingSectionDoesNotApply(): void
    {
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*.js]\nindent_size = 2\n",
            'a.py' => 'x',
        ]);

        $this->assertNull($this->resolve($project, 'a.py')->indentSize);
    }

    public function testNoConfigYieldsAllNull(): void
    {
        $project = new TempProject(['a.php' => 'x']);

        $c = $this->resolve($project, 'a.php');
        $this->assertNull($c->indentSize);
        $this->assertNull($c->usesTabs);
        $this->assertNull($c->eol);
    }

    public function testResolvesForAPathThatDoesNotExistOnDiskYet(): void
    {
        // realpath() returns false for a not-yet-created file (e.g. resolving
        // config before the first save) — the walk must still fall back to the
        // raw path rather than choking on it.
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\nindent_size = 4\n",
        ]);

        $this->assertSame(4, $this->resolve($project, 'not-yet-saved.py')->indentSize);
    }

    public function testGlobMatchOnlyAppliesWithinItsOwnEditorconfigsDirectory(): void
    {
        // A non-matching section immediately above the matching one, in the
        // SAME .editorconfig file, must not swallow the rest of the sections.
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*.js]\nindent_size = 2\n[*.py]\nindent_size = 8\n",
            'a.py' => 'x',
        ]);

        $this->assertSame(8, $this->resolve($project, 'a.py')->indentSize);
    }

    public function testUnsetComparisonIsCaseInsensitive(): void
    {
        // charset() lowercases its raw value regardless, so an un-filtered
        // "UNSET" would surface as the bogus non-null charset 'unset' instead
        // of being stripped by the resolver's own case-insensitive check —
        // indent_size can't distinguish this (toPositiveInt() nulls any
        // non-numeric string on its own, masking the filter either way).
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\ncharset = utf-8\n[*.py]\ncharset = UNSET\n",
            'a.py' => 'x',
        ]);

        $this->assertNull($this->resolve($project, 'a.py')->charset);
    }

    public function testUnsetIsStrippedEvenInTheExactSpecCase(): void
    {
        // With array_filter() itself removed, even the canonical lowercase
        // "unset" would survive as a literal (bogus, non-null) property.
        $project = new TempProject([
            '.editorconfig' => "root = true\n[*]\ncharset = utf-8\n[*.py]\ncharset = unset\n",
            'a.py' => 'x',
        ]);

        $this->assertNull($this->resolve($project, 'a.py')->charset);
    }

    public function testGlobWithASlashMatchesTheCorrectlyStrippedRelativePath(): void
    {
        // A glob with no `/` is compiled to match the basename at ANY depth
        // (EditorConfigGlob::compile()'s "no separator" branch), which
        // tolerates a wrong/unstripped relativePath() completely by accident.
        // Only a glob CONTAINING a `/` is anchored strictly to the relative
        // path, which is the only way to actually exercise relativePath()'s
        // own prefix-stripping arithmetic.
        $project = new TempProject([
            '.editorconfig' => "root = true\n[sub/*.py]\nindent_size = 4\n",
            'sub/a.py' => 'x',
        ]);

        $this->assertSame(4, $this->resolve($project, 'sub/a.py')->indentSize);
    }
}
