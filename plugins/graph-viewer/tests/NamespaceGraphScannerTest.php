<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\NamespaceGraphScanner;
use Porta\Tests\Support\TempProject;

final class NamespaceGraphScannerTest extends TestCase
{
    private function project(): TempProject
    {
        return new TempProject([
            'composer.json' => (string) \json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
            'src/Editor/Document.php' => "<?php\nnamespace App\\Editor;\nuse App\\Support\\Text;\nuse App\\Editor\\Cursor;\nfinal class Document {}\n",
            'src/Editor/Cursor.php' => "<?php\nnamespace App\\Editor;\nfinal class Cursor {}\n",
            'src/Support/Text.php' => "<?php\nnamespace App\\Support;\nuse Psr\\Log\\LoggerInterface;\nfinal class Text {}\n",
            'src/Boot.php' => "<?php\nnamespace App;\nuse App\\Editor\\Document;\nfinal class Boot {}\n",
            'src/vendor/Ignored.php' => "<?php\nnamespace App\\Vendor;\nfinal class Ignored {}\n",
            'src/.hidden/Skipped.php' => "<?php\nnamespace App\\Hidden;\nfinal class Skipped {}\n",
            'src/no-namespace.php' => "<?php\nfinal class Plain {}\n",
            'src/foreign.php' => "<?php\nnamespace Other\\World;\nfinal class Foreign {}\n",
            'src/notes.txt' => 'not php at all',
        ]);
    }

    public function testScansBucketsAndEmitsClusteredDot(): void
    {
        $project = $this->project();
        $dot = new NamespaceGraphScanner($project->dir)->scan();

        $this->assertStringContainsString('digraph "architecture" {', $dot);
        $this->assertStringContainsString('label="App";', $dot, 'one cluster per composer root');
        $this->assertStringContainsString('"App\\\\Editor" [label="Editor"];', $dot);
        $this->assertStringContainsString('"App\\\\Support" [label="Support"];', $dot);
        $this->assertStringContainsString('"App" [label="App"];', $dot, 'a class directly in the prefix buckets to the root');
        $this->assertStringContainsString('"App\\\\Editor" -> "App\\\\Support";', $dot);
        $this->assertStringContainsString('"App" -> "App\\\\Editor";', $dot);
        $this->assertStringNotContainsString('Psr', $dot, 'imports outside every root resolve to nothing');
        $this->assertStringNotContainsString('Vendor', $dot, 'vendor/ dirs are skipped');
        $this->assertStringNotContainsString('Hidden', $dot, 'dot-dirs are skipped');
        $this->assertStringNotContainsString('"App\\\\Editor" -> "App\\\\Editor"', $dot, 'intra-bucket imports form no self-edge');
        $this->assertStringNotContainsString('Other', $dot, 'a file whose namespace matches no root is skipped');
        $this->assertStringNotContainsString('Plain', $dot, 'a namespace-less file is skipped');
    }

    public function testAnUnreadableFileIsSkipped(): void
    {
        $project = $this->project();
        \chmod($project->dir.'/src/Support/Text.php', permissions: 0o000);
        \chmod($project->dir.'/src/Boot.php', permissions: 0o000); // the first file found: the scan goes on past it
        $dot = new NamespaceGraphScanner($project->dir)->scan();
        \chmod($project->dir.'/src/Support/Text.php', permissions: 0o644);
        \chmod($project->dir.'/src/Boot.php', permissions: 0o644);

        $this->assertStringNotContainsString('"App\\\\Support" [label="Support"];', $dot, 'the unreadable file registered no node (its inbound edge, derived from a readable importer, remains)');
        $this->assertStringNotContainsString('"App" [label="App"];', $dot);
        $this->assertStringContainsString('"App\\\\Editor" [label="Editor"];', $dot, 'the rest of the scan proceeded');
    }

    /** So is a directory nobody may list — a root-owned Docker volume: the scan goes on past it. */
    public function testAnUnreadableDirectoryIsSkipped(): void
    {
        $project = $this->project();
        if (!$project->lock('src/Locked')) { // inside a namespace root, where the scan walks
            $this->markTestSkipped('this user reads every directory');
        }

        $this->assertStringContainsString('"App\\\\Editor" [label="Editor"];', new NamespaceGraphScanner($project->dir)->scan());
    }

    public function testOutputIsDeterministic(): void
    {
        $project = $this->project();
        $scanner = new NamespaceGraphScanner($project->dir);

        $this->assertSame($scanner->scan(), $scanner->scan(), 'byte-identical across runs — stable hash, no spurious re-layout');
    }

    public function testEmptyProjectYieldsAnEmptyGraph(): void
    {
        $project = new TempProject(['composer.json' => '{}']);

        $this->assertSame("digraph \"architecture\" {\n    rankdir=LR;\n    node [shape=box];\n}\n", new NamespaceGraphScanner($project->dir)->scan());
    }

    /**
     * The whole graph, in order: roots, buckets and edges sorted however the
     * files were found; one edge per pair of buckets, every pair kept — two
     * roots' `Core` buckets are two buckets, not one label; a file
     * the scan cannot place skipped without losing the files after it, and an
     * import from outside the roots without losing the imports after it.
     */
    public function testTheGraphIsEveryBucketAndEdgeInOrder(): void
    {
        $project = new TempProject([
            'composer.json' => (string) \json_encode(['autoload' => ['psr-4' => ['Zed\\' => 'zed/', 'App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
            'src/Aaa/plain.php' => "<?php\nfinal class Plain {}\n",
            'src/Beta/One.php' => "<?php\nnamespace App\\Beta;\nuse Psr\\Log\\LoggerInterface;\nuse App\\Alpha\\Two;\nuse Zed\\Core\\Three;\nuse App\\Core\\Five;\n",
            'src/Core/Five.php' => "<?php\nnamespace App\\Core;\nuse App\\Alpha\\Two;\n",
            'src/Zz/Late.php' => "<?php\nnamespace App\\Aardvark;\nuse App\\Beta\\One;\n",
            'src/Alpha/Two.php' => "<?php\nnamespace App\\Alpha;\nuse Zed\\Core\\Three;\nuse Zed\\Core\\Four;\n",
            'src/Apple.php' => "<?php\nnamespace Apple\\Pie;\nuse App\\Alpha\\Two;\n",
            'zed/Core/Three.php' => "<?php\nnamespace Zed\\Core;\nuse App\\Alpha\\Two;\n",
        ]);

        $this->assertSame(<<<'DOT'
            digraph "architecture" {
                rankdir=LR;
                node [shape=box];
                subgraph cluster_0 {
                    label="App";
                    "App\\Aardvark" [label="Aardvark"];
                    "App\\Alpha" [label="Alpha"];
                    "App\\Beta" [label="Beta"];
                    "App\\Core" [label="Core"];
                }
                subgraph cluster_1 {
                    label="Zed";
                    "Zed\\Core" [label="Core"];
                }
                "App\\Aardvark" -> "App\\Beta";
                "App\\Alpha" -> "Zed\\Core";
                "App\\Beta" -> "App\\Alpha";
                "App\\Beta" -> "App\\Core";
                "App\\Beta" -> "Zed\\Core";
                "App\\Core" -> "App\\Alpha";
                "Zed\\Core" -> "App\\Alpha";
            }

            DOT, new NamespaceGraphScanner($project->dir)->scan());
    }
}
