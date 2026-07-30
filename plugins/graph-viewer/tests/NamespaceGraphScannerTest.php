<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\DotWriter;
use Porta\GraphViewer\NamespaceGraphScanner;
use Porta\Tests\Support\TempProject;

final class NamespaceGraphScannerTest extends TestCase
{
    private function project(): TempProject
    {
        return new TempProject([
            'composer.json' => (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
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

        $this->assertStringContainsString('digraph architecture {', $dot);
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
        chmod($project->dir.'/src/Support/Text.php', permissions: 0o000);
        $dot = new NamespaceGraphScanner($project->dir)->scan();
        chmod($project->dir.'/src/Support/Text.php', permissions: 0o644);

        $this->assertStringNotContainsString('"App\\\\Support" [label="Support"];', $dot, 'the unreadable file registered no node (its inbound edge, derived from a readable importer, remains)');
        $this->assertStringContainsString('"App\\\\Editor" [label="Editor"];', $dot, 'the rest of the scan proceeded');
    }

    public function testOutputIsDeterministic(): void
    {
        $project = $this->project();
        $scanner = new NamespaceGraphScanner($project->dir);

        $this->assertSame($scanner->scan(), $scanner->scan(), 'byte-identical across runs — stable hash, no spurious re-layout');
    }

    public function testDotWriterEscapesQuotesAndBackslashes(): void
    {
        $dot = DotWriter::write(['A"B' => ['Ns\\Sub' => 'Su"b']], [['Ns\\Sub', 'Ns\\Sub']]);

        $this->assertStringContainsString('label="A\\"B";', $dot);
        $this->assertStringContainsString('"Ns\\\\Sub" [label="Su\\"b"];', $dot);
        $this->assertStringContainsString('"Ns\\\\Sub" -> "Ns\\\\Sub";', $dot);
    }

    public function testEmptyProjectYieldsAnEmptyGraph(): void
    {
        $project = new TempProject(['composer.json' => '{}']);

        $this->assertSame("digraph architecture {\n    rankdir=LR;\n    node [shape=box];\n}\n", new NamespaceGraphScanner($project->dir)->scan());
    }
}
