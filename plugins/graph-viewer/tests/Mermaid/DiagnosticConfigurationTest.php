<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class DiagnosticConfigurationTest extends TestCase
{
    #[DataProvider('levels')]
    public function testLogLevelsDoNotChangeOrRestrictTheNativeScene(int|string $level): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $configuration = MermaidConfiguration::parse((object) ['logLevel' => $level], $statement);
        foreach (['sequence', 'pie', 'packet', 'git', 'usecase', 'architecture', 'other'] as $family) {
            $configuration->validateFamily($family);
        }
        $base = new MermaidConfiguration(sequenceNumbers: true);
        $merged = $base->overlay($configuration);
        $this->assertTrue($merged->sequenceNumbers);
        $this->assertSame([$configuration->frontMatter, $configuration->inits], [$merged->frontMatter, $merged->inits]);
        $source = "flowchart LR\nA-->B";
        $with = NativeFixtureScene::render('%%{init: {logLevel: '.\json_encode($level, \JSON_THROW_ON_ERROR).'}}%%'.$source);
        $this->assertEquals(NativeFixtureScene::render($source)->graph, $with->graph);
    }

    public static function levels(): iterable
    {
        foreach ([0, 1, 2, 3, 4, 5, 'trace', 'debug', 'info', 'warn', 'error', 'fatal'] as $level) {
            yield (string) $level => [$level];
        }
    }

    #[DataProvider('invalidLevels')]
    public function testInvalidLevelsHaveLocatedDiagnostics(mixed $level): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Mermaid logLevel must be');
        MermaidConfiguration::parse((object) ['logLevel' => $level], new MermaidStatement('', offset: 20, line: 2, column: 3));
    }

    public static function invalidLevels(): iterable
    {
        foreach ([-1, 6, 1.5, true, null, [], new \stdClass(), 'DEBUG', '1', 'warning'] as $level) {
            yield [$level];
        }
    }
}
