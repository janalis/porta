<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Assert;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** The pinned upstream examples of a diagram family, and what every family's corpus asserts of them. */
final class NativeCorpus
{
    /** @return iterable<string, array{string}> each `fixtures/mermaid/$family/*.mmd`, by its file name */
    public static function of(string $family): iterable
    {
        $found = \glob(__DIR__.'/../fixtures/mermaid/'.$family.'/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path];
        }
    }

    /** The example at $path renders natively to rows, each traced to its source, none wider than $width. */
    public static function assertRendersWithin(string $path, int $width): void
    {
        $result = NativeFixtureRows::render((string) \file_get_contents($path), $width);
        Assert::assertNotEmpty($result->rows);
        Assert::assertCount(\count($result->rows), $result->origins);
        foreach ($result->rows as $row) {
            Assert::assertLessThanOrEqual($width, AnsiUtils::visibleWidth($row));
        }
    }

    /** The example's body — its front matter and init directives taken off — renders to rows and to SVG. */
    public static function assertBodyRendersToRowsAndSvg(string $path): void
    {
        $source = (string) \file_get_contents($path);
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: $source) ?? '';
        $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source) ?? '';
        Assert::assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        Assert::assertStringContainsString('<svg', NativeFixtureExport::render($source, Format::Svg));
    }
}
