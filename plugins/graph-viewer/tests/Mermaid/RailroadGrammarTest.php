<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\RailroadDiagram;
use Porta\GraphViewer\Mermaid\RailroadExpression;
use Porta\GraphViewer\Mermaid\RailroadLayout;
use Porta\GraphViewer\Mermaid\RailroadLexer;
use Porta\GraphViewer\Mermaid\RailroadMeasure;
use Porta\GraphViewer\Mermaid\RailroadParser;

final class RailroadGrammarTest extends TestCase
{
    private static function parse(string $source): RailroadDiagram
    {
        $parser = new RailroadParser();
        foreach (new RailroadLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RailroadLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testAbnfFiniteCountsAreNotExpandedAndOptionalBoundsAreRecognized(): void
    {
        $source = "railroad-abnf-beta\nexact = 3ALPHA ;\nbounded = 2*5DIGIT ;\noptional = 0*1\"x\" ;\nopen = 2*ALPHA ;";
        $diagram = self::parse($source);
        $exact = $diagram->rules[0]['definition'];
        $bounded = $diagram->rules[1]['definition'];
        $this->assertSame([3, 3], [$exact->min, $exact->max]);
        $this->assertSame('3 times', RailroadMeasure::countLabel($exact));
        $this->assertSame([2, 5], [$bounded->min, $bounded->max]);
        $this->assertSame('2..5 times', RailroadMeasure::countLabel($bounded));
        $this->assertSame('optional', $diagram->rules[2]['definition']->type);
        $this->assertSame('2..many times', RailroadMeasure::countLabel($diagram->rules[3]['definition']));
        $scene = self::scene($source);
        $this->assertCount(8, $scene->selectionGraph->nodes);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('3 times', $svg);
        $this->assertStringContainsString('2..5 times', $svg);
        $this->assertLessThan(160, $scene->graph->height);
    }

    public function testLiteralEscapesAreNotationSpecificAndMetadataIsNormalized(): void
    {
        $source =
            'railroad-ebnf-beta'
            ."\r\n"
            .'title "A\tB" %% title comment'
            ."\r\n"
            .'accTitle:  Accessible  title'
            ."\r\n"
            .'accDescr { First  line'
            ."\r\n\r\n"
            .' Last line }'
            ."\r\n"
            .'x = "a\nb\rc\td\bf\v\0" ;';
        $diagram = self::parse($source);
        $this->assertSame("A\tB", $diagram->metadata['title']);
        $this->assertSame('Accessible title', $diagram->metadata['accTitle']);
        $this->assertSame("First line\nLast line", $diagram->metadata['accDescr']);
        $this->assertSame("a\nb\rc\tdbfv0", $diagram->rules[0]['definition']->label);
        $abnf = self::parse('railroad-abnf-beta'."\n".'title "A\nB"'."\n".'x = "a\nb" ;');
        $this->assertSame('A\nB', $abnf->metadata['title']);
        $this->assertSame('a\nb', $abnf->rules[0]['definition']->label);
        $this->assertStringContainsString('Accessible', $diagram->metadata['accTitle']);
    }

    public function testTracksKeepBranchNodesApartAndDecorationsOutsideSelection(): void
    {
        $source = "railroad-ebnf-beta\ntitle Grammar\nentry = (\"a\" | \"longer\") [name] {\",\" name} ;\nname = \"x\"+ ;";
        $scene = self::scene($source);
        $nodes = \array_values($scene->selectionGraph->nodes);
        $this->assertCount(8, $nodes);
        foreach ($nodes as $index => $node) {
            $this->assertNotNull($scene->origin($node->id));
            foreach (\array_slice($nodes, $index + 1) as $other) {
                $overlapX = \abs($node->x - $other->x) < ((($node->w + $other->w) / 2) - 0.001);
                $overlapY = \abs($node->y - $other->y) < ((($node->h + $other->h) / 2) - 0.001);
                $this->assertFalse($overlapX && $overlapY, $node->name.' overlaps '.$other->name);
            }
        }
        foreach ($scene->graph->nodes as $node) {
            if ('@marker' !== $node->name) {
                continue;
            }
            $this->assertNull($scene->selectable($node->id));
        }
        $this->assertNotEmpty($scene->graph->edges);
        foreach ($scene->graph->edges as $edge) {
            $this->assertTrue($edge->decoration);
        }
        $this->assertSame(2, $scene->origin($scene->onLine(2)));
        $this->assertStringContainsString('Grammar', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    public function testAllPrefixesCanBeDetectedAfterTheirOwnComments(): void
    {
        foreach ([
            "/* Comment */\nrailroad-beta\nx = terminal(\"x\");",
            "(* Comment *)\nrailroad-ebnf-beta\nx = \"x\";",
            "# Comment\nrailroad-peg-beta\nx <- \"x\";",
        ] as $source) {
            $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        }
        $this->assertSame([], self::scene('railroad-beta')->graph->nodes);
    }

    public function testExceptionLookaheadAndMetadataNamedRulesFollowThePinnedTransformations(): void
    {
        $ebnf = self::parse("railroad-ebnf-beta\nx = A - B ;");
        $expression = $ebnf->rules[0]['definition'];
        $this->assertSame('sequence', $expression->type);
        $this->assertSame(['A', '-', 'B'], \array_map(static fn (RailroadExpression $node): string => $node->label, $expression->children));
        $peg = self::parse("railroad-peg-beta\nx <- !(A B)* ;");
        $this->assertSame('!(...)', $peg->rules[0]['definition']->label);
        $names = self::parse('railroad-beta'."\n".'accTitle=terminal("a"); accDescr=terminal("b");');
        $this->assertSame(['accTitle', 'accDescr'], \array_column($names->rules, 'name'));
        $prefix = self::parse('railroad-beta'."\n".'titleCase=terminal("a");');
        $this->assertSame('Case', $prefix->rules[0]['name']);
    }

    public function testOptionalTracksBypassAndRepetitionsReturnBelowTheirOperand(): void
    {
        foreach (['[A]' => [true, false], 'A+' => [false, true], 'A*' => [true, true]] as $expression => [$upper, $lower]) {
            $scene = self::scene("railroad-ebnf-beta\nx = ".$expression.';');
            $operand = \array_values($scene->selectionGraph->nodes)[1];
            $above = false;
            $below = false;
            foreach ($scene->graph->edges as $edge) {
                foreach ($edge->points as [$x, $y]) {
                    $above = $above || $y < ($operand->y - ($operand->h / 2));
                    $below = $below || $y > ($operand->y + ($operand->h / 2));
                }
            }
            $this->assertSame($upper, $above, $expression.' optional bypass');
            $this->assertSame($lower, $below, $expression.' repetition return');
        }
    }

    public static function invalid(): iterable
    {
        yield 'missing header' => ['', 'header'];
        yield 'wrong header' => ['railroad', 'header'];
        yield 'unfinished' => ["railroad-beta\nx=terminal(\"a\")", 'semicolon'];
        yield 'unknown constructor' => ["railroad-beta\nx=unknown(\"a\");", 'constructor'];
        yield 'reserved identifier' => ["railroad-beta\nsequence=terminal(\"a\");", 'identifier'];
        yield 'bad identifier' => ["railroad-beta\n22=terminal(\"a\");", 'identifier'];
        yield 'bad literal' => ["railroad-beta\nx=terminal(a);", 'quoted'];
        yield 'bad token' => ["railroad-beta\n@", 'token'];
        yield 'peg brackets' => ["railroad-peg-beta\nx <- [A];", 'delimiter'];
        yield 'abnf braces' => ["railroad-abnf-beta\nx = {A};", 'delimiter'];
        yield 'abnf underscore' => ["railroad-abnf-beta\nx = _a;", 'identifier'];
        yield 'abnf single quotes' => ["railroad-abnf-beta\nx = 'a';", 'token'];
        yield 'repeat bound' => ["railroad-abnf-beta\nx = 1000001A;", 'million'];
        yield 'repeat max bound' => ["railroad-abnf-beta\nx = *1000001A;", 'million'];
        yield 'postfix bound' => ["railroad-ebnf-beta\nx = A".\str_repeat('?', times: 129).';', 'postfix'];
        yield 'group depth' => ["railroad-ebnf-beta\nx = ".\str_repeat('(', times: 129).'A'.\str_repeat(')', times: 129).';', 'nesting'];
        yield 'combined tree depth' => ["railroad-ebnf-beta\nx = (A".\str_repeat('*', times: 70).')'.\str_repeat('*', times: 70).';', 'tree'];
        yield 'field bound' => ["railroad-ebnf-beta\nx = ".\str_repeat('A ', times: 8193).';', 'field'];
        yield 'rules bound' => ["railroad-beta\n".\str_repeat('x=terminal("a");', times: 4097), 'rule limit'];
        yield 'token size' => ["railroad-beta\nx=terminal(\"".\str_repeat('x', times: 65_537).'");', 'limit'];
        yield 'token bound' => ["railroad-beta\n".\str_repeat(" %% note\n", times: 65_537), 'limit'];
    }

    #[DataProvider('invalid')]
    public function testMalformedAndExcessiveSourcesFailBeforeLayout(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }
}
