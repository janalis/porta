<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Tests\Support\TempProject;

final class EditorConfigResolverTest extends TestCase
{
    private function resolve(TempProject $project, string $relative)
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
}
